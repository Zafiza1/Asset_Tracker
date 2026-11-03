<?php

namespace App\Http\Controllers\Tenant;

use App\Domain\Assets\AssetScope;
use App\Domain\Assets\AssetStatus;
use App\Domain\Assets\Models\Asset;
use App\Domain\Assets\Services\AssetService;
use App\Domain\Organization\Models\Location;
use App\Domain\Organization\Models\OrganizationModule;
use App\Domain\Shared\Exceptions\ApiException;
use App\Domain\TenantMaster\Models\TenantAssetCategory;
use App\Domain\TenantMaster\Services\CategoryAttributeService;
use App\Http\Controllers\Controller;
use App\Http\Resources\AssetResource;
use App\Http\Resources\AssetTransactionResource;
use App\Http\Resources\CategoryAttributeResource;
use App\Http\Support\ImportColumn;
use App\Http\Support\ListQuery;
use App\Http\Support\SpreadsheetImport;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Asset Management container: list/search, detail, register, edit, deactivate, reactivate. */
class AssetController extends Controller
{
    private const RELATIONS = ['category', 'location'];

    private const SORTS = ['asset_number' => 'asset_number', 'name' => 'name', 'created_at' => 'created_at',
        'acquisition_date' => 'acquisition_date', 'status' => 'status'];

    /**
     * Fields that may be set on many assets at once (name and serial number are per asset).
     * Category fields ({key: value}) only touch the keys sent; a key is ignored on assets whose
     * category does not have it.
     */
    private const BULK_EDITABLE = ['category_id', 'acquisition_date', 'acquisition_cost', 'description', 'attributes'];

    public function __construct(
        private readonly AssetService $assets,
        private readonly AssetScope $scope,
        private readonly CategoryAttributeService $attributes,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $query = $this->filtered($request);
        ListQuery::sort($query, $request, self::SORTS, '-created_at');

        return AssetResource::collection($query->paginate(ListQuery::perPage($request))->withQueryString());
    }

    /**
     * Assets in the data scope matching the list filters (search, categories with their
     * subcategories, location with its sub-locations, status, archived), with category and location.
     */
    private function filtered(Request $request): Builder
    {
        $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            // Comma-separated; a category also matches its subcategories.
            'category_ids' => ['nullable', 'string', 'max:2000'],
            'location_id' => ['nullable', 'uuid'],
            'status' => ['nullable', Rule::in(AssetStatus::ALL)],
            'archived' => ['nullable', Rule::in(['only', 'all'])],
        ]);
        $categoryIds = array_values(array_filter(explode(',', (string) $request->query('category_ids'))));
        $request->merge(['category_id_list' => $categoryIds])->validate(['category_id_list.*' => ['uuid']]);

        $query = $this->scope->apply(Asset::query())->with(self::RELATIONS)
            ->when($request->query('search'), fn ($q, $term) => $q->where(fn ($w) => $w
                ->where('name', 'ilike', ListQuery::like($term))
                ->orWhere('asset_number', 'ilike', ListQuery::like($term))
                ->orWhere('serial_number', 'ilike', ListQuery::like($term))))
            ->when($categoryIds, fn ($q) => $q->whereIn('category_id', $this->subtree(TenantAssetCategory::query(), $categoryIds)))
            ->when($request->query('location_id'), fn ($q, $id) => $q->whereIn('location_id', $this->subtree(Location::query(), [$id])))
            ->when($request->query('status'), fn ($q, $status) => $q->where('status', $status));

        match ($request->query('archived')) {
            'only' => $query->whereNotNull('archived_at'),
            'all' => null,
            default => $query->whereNull('archived_at'),
        };

        return $query;
    }

    /** Includes the category's fields, so the values can be shown with their labels. */
    public function show(Asset $asset): AssetResource
    {
        return (new AssetResource($asset->load([...self::RELATIONS, 'creator'])))
            ->additional(['attribute_fields' => CategoryAttributeResource::collection($this->attributes->effective($asset->category_id))]);
    }

    /** The asset a scanned barcode encodes (its asset number), as show(); archived ones too. */
    public function lookup(Request $request): AssetResource
    {
        if (! OrganizationModule::isEnabled(OrganizationModule::BARCODE)) {
            throw ApiException::forbidden('MODULE_DISABLED', 'Modul Barcode belum diaktifkan oleh administrator organisasi.');
        }
        $code = trim($request->validate(['code' => ['required', 'string', 'max:200']])['code']);
        $asset = $this->scope->apply(Asset::query())->where('asset_number', $code)->first()
            ?? throw new ApiException('ASSET_NOT_FOUND', 'Aset dengan kode tersebut tidak ditemukan atau di luar cakupan data Anda.', 404);

        return $this->show($asset);
    }

    public function store(Request $request): AssetResource
    {
        $data = $request->validate([
            ...$this->descriptiveRules(creating: true),
            'location_id' => ['nullable', 'uuid'],
            'status' => ['nullable', Rule::in(AssetStatus::INITIAL)],
            'registered_on' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:today', 'after:1999-12-31'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        return new AssetResource($this->assets->create($data)->load(self::RELATIONS));
    }

    public function update(Request $request, Asset $asset): AssetResource
    {
        $data = $request->validate([
            ...$this->descriptiveRules(creating: false),
            'lock_version' => ['sometimes', 'integer'],
        ]);

        return new AssetResource($this->assets->update($asset, $data)->load(self::RELATIONS));
    }

    /** Same descriptive changes for several (active) assets; only the fields sent in `changes` are touched. */
    public function bulkUpdate(Request $request): JsonResponse
    {
        $data = $request->validate([
            'asset_ids' => ['required', 'array', 'min:1', 'max:1000'],
            'asset_ids.*' => ['required', 'uuid', 'distinct'],
            'changes' => ['required', 'array', 'min:1'],
            ...Arr::prependKeysWith(Arr::only($this->descriptiveRules(creating: false), self::BULK_EDITABLE), 'changes.'),
        ]);
        $changes = Arr::only($data['changes'] ?? [], self::BULK_EDITABLE);
        if ($changes === []) {
            throw ApiException::unprocessable('VALIDATION_FAILED', 'Pilih minimal satu data yang akan diubah.', ['fields' => ['changes' => ['Pilih minimal satu data yang akan diubah.']]]);
        }

        $assets = $this->scope->apply(Asset::query())->whereIn('id', $data['asset_ids'])->orderBy('asset_number')->get();
        if ($assets->count() !== count($data['asset_ids'])) {
            throw ApiException::unprocessable('INVALID_REFERENCE', 'Sebagian aset tidak ditemukan atau di luar cakupan data Anda.');
        }

        return response()->json(['data' => ['updated' => $this->assets->bulkUpdate($assets, $changes)]]);
    }

    /** The asset's photo; route binding applies the data scope, so out-of-scope assets are 404. */
    public function image(Asset $asset): StreamedResponse
    {
        $disk = Storage::disk(AssetService::IMAGE_DISK);
        if ($asset->image_path === null || ! $disk->exists($asset->image_path)) {
            throw new ApiException('IMAGE_NOT_FOUND', 'Aset ini belum memiliki gambar.', 404);
        }

        return $disk->response($asset->image_path, null, [
            'Cache-Control' => 'private, max-age=86400',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /** Raster formats only: an SVG could carry script. */
    public function uploadImage(Request $request, Asset $asset): AssetResource
    {
        $request->validate([
            'image' => ['required', 'file', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
        ], [
            'image.required' => 'Pilih file gambar.',
            'image.mimes' => 'Gambar harus berformat JPG, PNG, atau WEBP.',
            'image.max' => 'Ukuran gambar maksimal 5 MB.',
        ]);

        return new AssetResource($this->assets->setImage($asset, $request->file('image'))->load(self::RELATIONS));
    }

    public function deleteImage(Asset $asset): AssetResource
    {
        return new AssetResource($this->assets->setImage($asset, null)->load(self::RELATIONS));
    }

    public function archive(Request $request, Asset $asset): AssetResource
    {
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:500']]);

        return new AssetResource($this->assets->archive($asset, $data['reason'] ?? null)->load(self::RELATIONS));
    }

    public function restore(Asset $asset): AssetResource
    {
        return new AssetResource($this->assets->restore($asset)->load(self::RELATIONS));
    }

    /** Registers assets from an .xlsx (see the template); category and location are given by code. */
    public function import(Request $request): JsonResponse
    {
        $columns = $this->importColumns();
        $rows = SpreadsheetImport::read($request, $columns);
        $rules = $this->descriptiveRules(creating: true);

        $imported = SpreadsheetImport::run($rows, function (array $row) use ($rules, $columns) {
            $row['status'] = $this->importStatus($row['status']);
            $data = Validator::make($row, [
                ...Arr::only($rules, ['name', 'serial_number', 'acquisition_date', 'acquisition_cost', 'description']),
                'category_code' => ['required', 'string'],
                'location_code' => ['nullable', 'string'],
                'status' => ['nullable', Rule::in(AssetStatus::INITIAL)],
            ], SpreadsheetImport::messages(), SpreadsheetImport::attributes($columns))->validate();

            $data['category_id'] = SpreadsheetImport::idByCode(TenantAssetCategory::query(), $data['category_code'], 'Kategori');
            $data['location_id'] = SpreadsheetImport::idByCode(Location::query(), $data['location_code'] ?? null, 'Lokasi');
            $this->assets->create(Arr::except($data, ['category_code', 'location_code']));
        });

        return response()->json(['data' => ['imported' => $imported]]);
    }

    public function downloadTemplate(): BinaryFileResponse
    {
        return SpreadsheetImport::template('template_aset.xlsx', 'Template Import Pendataan Aset', $this->importColumns(), [
            'Kategori' => SpreadsheetImport::codeList(TenantAssetCategory::query()),
            'Lokasi' => SpreadsheetImport::codeList(Location::query()),
            'Status' => array_map(fn ($s) => AssetStatus::LABELS[$s], AssetStatus::INITIAL),
        ], [
            'Kategori dan lokasi harus sudah dibuat sebelum mengimpor aset. Nomor aset dibuat otomatis oleh sistem.',
        ]);
    }

    /**
     * The active assets matching the list filters, as an .xlsx to edit and upload to importEdits().
     * Location and status are shown for reference only: they change through transactions.
     */
    public function downloadEditSheet(Request $request): BinaryFileResponse
    {
        $query = $this->filtered($request)->whereNull('archived_at');
        SpreadsheetImport::assertExportable($query->count());

        $rows = $query->orderBy('asset_number')->get()->map(fn (Asset $a) => [
            'asset_number' => $a->asset_number,
            'name' => $a->name,
            'category_code' => SpreadsheetImport::codeItem($a->category),
            'serial_number' => $a->serial_number,
            'acquisition_date' => $a->acquisition_date,
            'acquisition_cost' => $a->acquisition_cost,
            'description' => $a->description,
            'location' => SpreadsheetImport::codeItem($a->location),
            'status' => AssetStatus::LABELS[$a->status] ?? $a->status,
            'id' => $a->id,
            'lock_version' => $a->lock_version,
        ]);

        return SpreadsheetImport::editSheet('edit_aset.xlsx', 'Edit Massal Pendataan Aset', $this->editColumns(), $rows, [
            'Kategori' => SpreadsheetImport::codeList(TenantAssetCategory::query()),
        ], [
            'Lokasi dan status tidak dapat diubah di sini; gunakan transaksi (mutasi, peminjaman, perbaikan). Atribut kategori diubah dari halaman detail aset.',
            'Jika aset diubah orang lain setelah file ini diunduh, baris aset tersebut ditolak. Unduh ulang file untuk mendapatkan data terbaru.',
        ]);
    }

    /** Applies an edited sheet from downloadEditSheet(): rows are matched by the hidden id, unchanged ones skipped. */
    public function importEdits(Request $request): JsonResponse
    {
        $columns = $this->editColumns();
        $rows = SpreadsheetImport::read($request, $columns);
        $rules = $this->descriptiveRules(creating: true);
        $seen = [];

        $updated = SpreadsheetImport::run($rows, function (array $row) use ($rules, $columns, &$seen) {
            $data = Validator::make($row, [
                ...Arr::only($rules, ['name', 'serial_number', 'acquisition_date', 'acquisition_cost', 'description']),
                'category_code' => ['required', 'string'],
                'lock_version' => ['nullable', 'integer'],
            ], SpreadsheetImport::messages(), SpreadsheetImport::attributes($columns))->validate();

            $asset = SpreadsheetImport::existing($this->scope->apply(Asset::query())->whereNull('archived_at'), $row['id'], $seen);
            $changes = SpreadsheetImport::changes($asset, [
                ...Arr::only($data, ['name', 'serial_number', 'acquisition_date', 'acquisition_cost', 'description']),
                'category_id' => SpreadsheetImport::idByCode(TenantAssetCategory::query(), $data['category_code'], 'Kategori'),
            ]);
            if ($changes === []) {
                return false;
            }
            if (isset($data['lock_version']) && (int) $data['lock_version'] !== $asset->lock_version) {
                throw ApiException::conflict('STALE_ASSET', 'Aset ini telah diubah setelah file diunduh. Unduh ulang file lalu ulangi perubahannya.');
            }
            $this->assets->update($asset, $changes);
        });

        return response()->json(['data' => ['updated' => $updated]]);
    }

    /** @return list<ImportColumn> the asset number is shown to tell assets apart, but not read back */
    private function editColumns(): array
    {
        return [
            new ImportColumn('asset_number', 'Nomor Aset (info)', hint: 'Nomor aset dibuat oleh sistem dan tidak dapat diubah.', width: 20, readOnly: true),
            new ImportColumn('name', 'Nama Aset', required: true, hint: 'Nama aset, maksimal 150 karakter.', width: 32),
            new ImportColumn('category_code', 'Kategori', required: true, hint: 'Pilih kategori aset dari daftar.', list: 'Kategori', width: 34),
            new ImportColumn('serial_number', 'Nomor Seri', required: true, hint: 'Nomor seri dari pabrik, harus unik.', width: 22),
            new ImportColumn('acquisition_date', 'Tanggal Perolehan', hint: 'Format YYYY-MM-DD. Contoh: 2024-01-15', type: ImportColumn::DATE, width: 20),
            new ImportColumn('acquisition_cost', 'Biaya Perolehan', hint: 'Harga perolehan dalam Rupiah, angka saja. Contoh: 15000000', type: ImportColumn::NUMBER, width: 20),
            new ImportColumn('description', 'Keterangan', hint: 'Catatan tambahan tentang aset. Kosongkan untuk menghapus.', width: 40),
            new ImportColumn('location', 'Lokasi (info)', hint: 'Lokasi saat ini; diubah lewat transaksi mutasi.', width: 30, readOnly: true),
            new ImportColumn('status', 'Status (info)', hint: 'Status saat ini; diubah lewat transaksi.', width: 18, readOnly: true),
            SpreadsheetImport::idColumn(),
            new ImportColumn('lock_version', 'Versi', type: ImportColumn::NUMBER, width: 8, hidden: true),
        ];
    }

    /** @return list<ImportColumn> */
    private function importColumns(): array
    {
        return [
            new ImportColumn('name', 'Nama Aset', required: true, hint: 'Nama aset, maksimal 150 karakter. Contoh: Laptop Dell Latitude 5440', width: 32),
            new ImportColumn('category_code', 'Kategori', required: true, hint: 'Pilih kategori aset dari daftar.', list: 'Kategori', width: 34),
            new ImportColumn('location_code', 'Lokasi', hint: 'Pilih lokasi aset dari daftar. Boleh dikosongkan.', list: 'Lokasi', width: 34),
            new ImportColumn('status', 'Status', hint: 'Pilih status awal. Jika kosong: Tersedia.', list: 'Status', width: 18),
            new ImportColumn('serial_number', 'Nomor Seri', required: true, hint: 'Nomor seri dari pabrik, harus unik. Contoh: SN-2024-0001', width: 22),
            new ImportColumn('acquisition_date', 'Tanggal Perolehan', hint: 'Tanggal pembelian/perolehan, format YYYY-MM-DD. Contoh: 2024-01-15', type: ImportColumn::DATE, width: 20),
            new ImportColumn('acquisition_cost', 'Biaya Perolehan', hint: 'Harga perolehan dalam Rupiah, angka saja. Contoh: 15000000', type: ImportColumn::NUMBER, width: 20),
            new ImportColumn('description', 'Keterangan', hint: 'Catatan tambahan tentang aset. Boleh dikosongkan.', width: 40),
        ];
    }

    /** The status column accepts the key (in_use) or its label (Digunakan); empty means available. */
    private function importStatus(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $key = str_replace(' ', '_', strtolower($value));
        $byLabel = array_search(strtolower($value), array_map('strtolower', AssetStatus::LABELS), true);

        return in_array($key, AssetStatus::ALL, true) ? $key : ($byLabel ?: $value);
    }

    /** Full history of one asset, newest first. */
    public function transactions(Request $request, Asset $asset): AnonymousResourceCollection
    {
        $history = $asset->transactions()->with(['fromLocation', 'toLocation', 'creator'])
            ->orderByDesc('seq')
            ->paginate(ListQuery::perPage($request, 10));

        return AssetTransactionResource::collection($history);
    }

    /** @return array<string, list<mixed>> */
    private function descriptiveRules(bool $creating): array
    {
        $required = $creating ? ['required'] : ['sometimes', 'required'];
        $optional = $creating ? ['nullable'] : ['sometimes', 'nullable'];

        return [
            'name' => [...$required, 'string', 'max:150'],
            'serial_number' => [...$required, 'string', 'max:100'],
            'category_id' => [...$required, 'uuid'],
            'acquisition_date' => [...$optional, 'date_format:Y-m-d', 'before_or_equal:today'],
            'acquisition_cost' => [...$optional, 'numeric', 'min:0', 'max:9999999999999999'],
            'description' => [...$optional, 'string', 'max:2000'],
            // Category fields, {key: value}; validated against the category by the service.
            'attributes' => [...$optional, 'array'],
        ];
    }

    /**
     * Ids of the given tree nodes and all their descendants (materialized path).
     *
     * @param  list<string>  $ids
     */
    private function subtree(Builder $query, array $ids): Builder
    {
        return $query->select('id')->where(function ($w) use ($ids) {
            foreach ($ids as $id) {
                $w->orWhere('path', 'like', '%/'.$id.'/%');
            }
        });
    }
}
