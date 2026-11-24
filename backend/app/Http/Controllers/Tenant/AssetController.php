<?php

namespace App\Http\Controllers\Tenant;

use App\Domain\Assets\AssetDataQuality;
use App\Domain\Assets\AssetScope;
use App\Domain\Assets\AssetStatus;
use App\Domain\Assets\AssetTimeline;
use App\Domain\Assets\Models\Asset;
use App\Domain\Assets\Services\AssetService;
use App\Domain\Identity\Models\OrganizationMember;
use App\Domain\Identity\Models\User;
use App\Domain\Organization\Models\Department;
use App\Domain\Organization\Models\Location;
use App\Domain\Organization\Models\OrganizationModule;
use App\Domain\Reports\AssetReport;
use App\Domain\Shared\Exceptions\ApiException;
use App\Domain\Shared\Tenancy\Tenancy;
use App\Domain\TenantMaster\Models\TenantAssetCategory;
use App\Domain\TenantMaster\Services\CategoryAttributeService;
use App\Http\Controllers\Controller;
use App\Http\Resources\AssetResource;
use App\Http\Resources\AssetTransactionResource;
use App\Http\Resources\CategoryAttributeResource;
use App\Http\Resources\Ref;
use App\Http\Support\ImportColumn;
use App\Http\Support\ListQuery;
use App\Http\Support\SpreadsheetImport;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Asset Management container: list/search, detail, register, edit, deactivate, reactivate. */
class AssetController extends Controller
{
    private const RELATIONS = ['category', 'location', 'department', 'custodianMember.user'];

    /** Most members the custodian picker lists per search. */
    private const MAX_CUSTODIAN_OPTIONS = 20;

    /** Most assets the Multiple Edit dialog can pick; the ids travel in the download URL. */
    private const MAX_EDIT_SELECTION = 100;

    /**
     * Fields that may be set on many assets at once (name and serial number are per asset).
     * Category fields ({key: value}) only touch the keys sent; a key is ignored on assets whose
     * category does not have it.
     */
    public function __construct(
        private readonly AssetService $assets,
        private readonly AssetScope $scope,
        private readonly CategoryAttributeService $attributes,
        private readonly Tenancy $tenancy,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $query = $this->filtered($request);
        // Ties (same status, same date) are broken by id, so no asset repeats or vanishes between pages.
        ListQuery::sort($query, $request, AssetReport::sorts(), '-created_at');

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
            // An optional field left empty (dashboard "Kelengkapan data").
            'missing' => ['nullable', Rule::in(array_keys(AssetDataQuality::FIELDS))],
            // Acquisition date range (inclusive); assets without a date never match.
            'acquired_from' => ['nullable', 'date_format:Y-m-d'],
            'acquired_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:acquired_from'],
            // F-09: who answers for the asset; `mine=1` = the current user.
            'custodian_id' => ['nullable', 'uuid'],
            'department_id' => ['nullable', 'uuid'],
            'mine' => ['nullable', 'boolean'],
            // Loans past their planned return date (Beranda "Peminjaman lewat batas").
            'overdue' => ['nullable', 'boolean'],
        ]);
        $categoryIds = array_values(array_filter(explode(',', (string) $request->query('category_ids'))));
        $request->merge(['category_id_list' => $categoryIds])->validate(['category_id_list.*' => ['uuid']]);

        $query = $this->scope->apply(Asset::query())->with(self::RELATIONS)
            ->when($request->query('search'), fn ($q, $term) => $q->where(fn ($w) => $w
                ->where('name', 'ilike', ListQuery::like($term))
                ->orWhere('serial_number', 'ilike', ListQuery::like($term))))
            ->when($categoryIds, fn ($q) => $q->whereIn('category_id', $this->subtree(TenantAssetCategory::query(), $categoryIds)))
            ->when($request->query('location_id'), fn ($q, $id) => $q->whereIn('location_id', $this->subtree(Location::query(), [$id])))
            ->when($request->query('status'), fn ($q, $status) => $q->where('status', $status))
            ->when($request->query('custodian_id'), fn ($q, $id) => $q->where('custodian_user_id', $id))
            ->when($request->query('department_id'), fn ($q, $id) => $q->where('department_id', $id))
            ->when($request->boolean('mine'), fn ($q) => $q->where('custodian_user_id', $this->tenancy->tenant()->userId()))
            ->when($request->boolean('overdue'), fn ($q) => $q->where('status', AssetStatus::ON_LOAN)
                ->whereDate('loan_due_date', '<', CarbonImmutable::now($this->tenancy->tenant()->organization->timezone ?: 'UTC')->toDateString()))
            ->when($request->query('acquired_from'), fn ($q, $date) => $q->whereDate('acquisition_date', '>=', $date))
            ->when($request->query('acquired_to'), fn ($q, $date) => $q->whereDate('acquisition_date', '<=', $date))
            ->when($request->query('missing'), fn ($q, $field) => $q->whereRaw('NOT ('.AssetDataQuality::filledCondition($field).')'));

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

    /** The asset a scanned barcode encodes (its id) or a typed serial number names, as show(); archived ones too. */
    public function lookup(Request $request): AssetResource
    {
        if (! OrganizationModule::isEnabled(OrganizationModule::BARCODE)) {
            throw ApiException::forbidden('MODULE_DISABLED', 'Modul Barcode belum diaktifkan oleh administrator organisasi.');
        }
        $code = trim($request->validate(['code' => ['required', 'string', 'max:200']])['code']);
        $query = $this->scope->apply(Asset::query());
        // Labels encode the id; a person typing a code in uses the serial number (unique per organization).
        $asset = (Str::isUuid($code) ? (clone $query)->whereKey(strtolower($code))->first() : null)
            ?? $query->whereRaw('lower(serial_number) = ?', [mb_strtolower($code)])->first()
            ?? throw new ApiException('ASSET_NOT_FOUND', 'Aset dengan kode tersebut tidak ditemukan atau di luar cakupan data Anda.', 404);

        return $this->show($asset);
    }

    public function store(Request $request): AssetResource
    {
        $data = $request->validate([
            ...$this->descriptiveRules(creating: true),
            'location_id' => ['required', 'uuid'],
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

    /** The asset's document, always as a download; route binding applies the data scope. */
    public function document(Asset $asset): StreamedResponse
    {
        $disk = Storage::disk(AssetService::IMAGE_DISK);
        if ($asset->document_path === null || ! $disk->exists($asset->document_path)) {
            throw new ApiException('DOCUMENT_NOT_FOUND', 'Aset ini belum memiliki dokumen.', 404);
        }

        return $disk->download($asset->document_path, $asset->document_name, ['X-Content-Type-Options' => 'nosniff']);
    }

    public function uploadDocument(Request $request, Asset $asset): AssetResource
    {
        $request->validate([
            'document' => ['required', 'file', 'mimes:pdf,doc,docx,xls,xlsx,jpg,jpeg,png', 'max:10240'],
        ], [
            'document.required' => 'Pilih file dokumen.',
            'document.mimes' => 'Dokumen harus berformat PDF, Word, Excel, JPG, atau PNG.',
            'document.max' => 'Ukuran dokumen maksimal 10 MB.',
        ]);

        return new AssetResource($this->assets->setDocument($asset, $request->file('document'))->load(self::RELATIONS));
    }

    public function deleteDocument(Asset $asset): AssetResource
    {
        return new AssetResource($this->assets->setDocument($asset, null)->load(self::RELATIONS));
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
                'location_code' => ['required', 'string'],
                'status' => ['nullable', Rule::in(AssetStatus::INITIAL)],
                'condition' => ['nullable', 'string'],
                ...self::RESPONSIBILITY_RULES,
            ], SpreadsheetImport::messages(), SpreadsheetImport::attributes($columns))->validate();

            $data['category_id'] = SpreadsheetImport::idByCode(TenantAssetCategory::query(), $data['category_code'], 'Kategori');
            $data['location_id'] = SpreadsheetImport::idByCode(Location::query(), $data['location_code'], 'Lokasi');
            $data['attributes'] = $this->importConditionValue($data['category_id'], $data['condition'] ?? null);
            $data += $this->importResponsibility($data);
            $this->assets->create(Arr::except($data, ['category_code', 'location_code', 'condition', 'department_code', 'custodian_email']));
        });

        return response()->json(['data' => ['imported' => $imported]]);
    }

    public function downloadTemplate(): BinaryFileResponse
    {
        return SpreadsheetImport::template('template_aset.xlsx', 'Template Import Pendataan Aset', $this->importColumns(), [
            'Kategori' => SpreadsheetImport::codeList(TenantAssetCategory::query()),
            'Lokasi' => SpreadsheetImport::codeList(Location::query()),
            'Status' => array_map(fn ($s) => AssetStatus::LABELS[$s], AssetStatus::INITIAL),
            'Kondisi' => array_column(self::conditionOptions(), 'label'),
            'Departemen' => SpreadsheetImport::codeList(Department::query()),
        ], [
            'Kategori dan lokasi harus sudah dibuat sebelum mengimpor aset. Nomor aset dibuat otomatis oleh sistem.',
            'Penanggung jawab diisi dengan email anggota aktif organisasi. Departemen dan penanggung jawab boleh dikosongkan.',
        ]);
    }

    /**
     * The active assets matching the list filters, as an .xlsx to edit and upload to importEdits().
     * Location and status are shown for reference only: they change through transactions.
     */
    public function downloadEditSheet(Request $request): BinaryFileResponse
    {
        // Comma-separated asset ids picked in the Multiple Edit dialog; they replace the list filters.
        $request->validate(['ids' => ['nullable', 'string', 'max:'.(self::MAX_EDIT_SELECTION * 37)]]);
        $ids = array_values(array_unique(array_filter(explode(',', (string) $request->query('ids')))));
        $request->merge(['id_list' => $ids])->validate([
            'id_list' => ['array', 'max:'.self::MAX_EDIT_SELECTION],
            'id_list.*' => ['uuid'],
        ]);

        $query = $ids !== []
            ? $this->scope->apply(Asset::query())->with(self::RELATIONS)->whereIn('id', $ids)->whereNull('archived_at')
            : $this->filtered($request)->whereNull('archived_at');
        SpreadsheetImport::assertExportable($query->count());

        $rows = $query->orderBy('asset_number')->get()->map(fn (Asset $a) => [
            'id' => $a->id,
            'name' => $a->name,
            'category_code' => SpreadsheetImport::codeItem($a->category),
            'serial_number' => $a->serial_number,
            'acquisition_date' => $a->acquisition_date,
            'acquisition_cost' => $a->acquisition_cost,
            'description' => $a->description,
            'department_code' => SpreadsheetImport::codeItem($a->department),
            'custodian_email' => $a->custodianMember?->user?->email,
            'location' => SpreadsheetImport::codeItem($a->location),
            'status' => AssetStatus::LABELS[$a->status] ?? $a->status,
            'updated_at' => $this->lastChanged($a),
        ]);

        return SpreadsheetImport::editSheet('edit_aset.xlsx', 'Multiple Edit Aset', $this->editColumns(), $rows, [
            'Kategori' => SpreadsheetImport::codeList(TenantAssetCategory::query()),
            'Departemen' => SpreadsheetImport::codeList(Department::query()),
        ], [
            'Penanggung jawab diisi dengan email anggota aktif organisasi; kosongkan untuk melepas penanggung jawab.',
            'Lokasi dan status tidak dapat diubah di sini; gunakan transaksi (mutasi, peminjaman, perbaikan). Atribut kategori diubah dari halaman detail aset.',
            'Kolom "Terakhir Diubah" dipakai untuk memeriksa perubahan: jika aset diubah orang lain setelah file ini diunduh, baris aset tersebut ditolak. Unduh ulang file untuk mendapatkan data terbaru.',
        ]);
    }

    /** Applies an edited sheet from downloadEditSheet(): rows are matched by the hidden asset id, unchanged ones skipped. */
    public function importEdits(Request $request): JsonResponse
    {
        $columns = $this->editColumns();
        $rows = SpreadsheetImport::read($request, $columns, edit: true);
        $rules = $this->descriptiveRules(creating: true);
        $seen = [];

        $updated = SpreadsheetImport::run($rows, function (array $row) use ($rules, $columns, &$seen) {
            $data = Validator::make($row, [
                ...Arr::only($rules, ['name', 'serial_number', 'acquisition_date', 'acquisition_cost', 'description']),
                'category_code' => ['required', 'string'],
                ...self::RESPONSIBILITY_RULES,
            ], SpreadsheetImport::messages(), SpreadsheetImport::attributes($columns))->validate();

            $asset = SpreadsheetImport::existing($this->scope->apply(Asset::query())->whereNull('archived_at'), $row['id'], $seen);
            $changes = SpreadsheetImport::changes($asset, [
                ...Arr::only($data, ['name', 'serial_number', 'acquisition_date', 'acquisition_cost', 'description']),
                'category_id' => SpreadsheetImport::idByCode(TenantAssetCategory::query(), $data['category_code'], 'Kategori'),
                ...$this->importResponsibility($data),
            ]);
            if ($changes === []) {
                return false;
            }
            if (($row['updated_at'] ?? null) !== $this->lastChanged($asset)) {
                throw ApiException::conflict('STALE_ASSET', 'Aset ini telah diubah setelah file diunduh. Unduh ulang file lalu ulangi perubahannya.');
            }
            $this->assets->update($asset, $changes);
        });

        return response()->json(['data' => ['updated' => $updated]]);
    }

    /** @return list<ImportColumn> rows are matched by the hidden asset id, which cannot be changed */
    private function editColumns(): array
    {
        return [
            new ImportColumn('name', 'Nama Aset', required: true, hint: 'Nama aset, maksimal 150 karakter.', width: 32),
            new ImportColumn('category_code', 'Kategori', required: true, hint: 'Pilih kategori aset dari daftar.', list: 'Kategori', width: 34),
            new ImportColumn('serial_number', 'No Seri', hint: 'Nomor seri aset yang dipakai sebagai nomor alias (misalnya nomor seri pabrik), harus unik bila diisi.', width: 22),
            new ImportColumn('acquisition_date', 'Tanggal Perolehan', hint: 'Format YYYY-MM-DD. Contoh: 2024-01-15', type: ImportColumn::DATE, width: 20),
            new ImportColumn('acquisition_cost', 'Biaya Perolehan', hint: 'Harga perolehan dalam Rupiah, angka saja. Contoh: 15000000', type: ImportColumn::NUMBER, width: 20),
            new ImportColumn('description', 'Keterangan', hint: 'Catatan tambahan tentang aset. Kosongkan untuk menghapus.', width: 40),
            ...self::responsibilityColumns(),
            new ImportColumn('location', 'Lokasi (info)', hint: 'Lokasi saat ini; diubah lewat transaksi mutasi.', width: 30, readOnly: true),
            new ImportColumn('status', 'Status (info)', hint: 'Status saat ini; diubah lewat transaksi.', width: 18, readOnly: true),
            new ImportColumn('updated_at', 'Terakhir Diubah (info)', hint: 'Waktu aset terakhir disimpan. Dipakai untuk memeriksa apakah aset diubah orang lain setelah file diunduh; jangan diubah.', width: 22, readOnly: true, readBack: true),
            SpreadsheetImport::idColumn(),
        ];
    }

    /**
     * When the asset was last saved, in the organization's time zone, as written to the edit sheet.
     * Every save bumps updated_at, so a different value on upload means someone changed the asset
     * after the file was downloaded.
     */
    private function lastChanged(Asset $asset): ?string
    {
        return $asset->updated_at?->copy()->timezone($this->tenancy->tenant()->organization->timezone ?: 'UTC')->format('Y-m-d H:i:s');
    }

    /** @return list<ImportColumn> */
    private function importColumns(): array
    {
        return [
            new ImportColumn('name', 'Nama Aset', required: true, hint: 'Nama aset, maksimal 150 karakter. Contoh: Laptop Dell Latitude 5440', width: 32),
            new ImportColumn('category_code', 'Kategori', required: true, hint: 'Pilih kategori aset dari daftar.', list: 'Kategori', width: 34),
            new ImportColumn('location_code', 'Lokasi', required: true, hint: 'Pilih lokasi aset dari daftar.', list: 'Lokasi', width: 34),
            new ImportColumn('status', 'Status', hint: 'Pilih status awal. Jika kosong: Aktif.', list: 'Status', width: 18),
            new ImportColumn('condition', 'Kondisi', required: true, hint: 'Pilih kondisi aset dari daftar.', list: 'Kondisi', width: 18),
            new ImportColumn('serial_number', 'No Seri', hint: 'Nomor seri aset yang dipakai sebagai nomor alias (misalnya nomor seri pabrik), harus unik bila diisi. Contoh: SN-2024-0001', width: 22),
            new ImportColumn('acquisition_date', 'Tanggal Perolehan', hint: 'Tanggal pembelian/perolehan, format YYYY-MM-DD. Contoh: 2024-01-15', type: ImportColumn::DATE, width: 20),
            new ImportColumn('acquisition_cost', 'Biaya Perolehan', hint: 'Harga perolehan dalam Rupiah, angka saja. Contoh: 15000000', type: ImportColumn::NUMBER, width: 20),
            new ImportColumn('description', 'Keterangan', hint: 'Catatan tambahan tentang aset. Boleh dikosongkan.', width: 40),
            ...self::responsibilityColumns(),
        ];
    }

    /** Spreadsheet validation of the F-09 columns; the references are resolved by importResponsibility(). */
    private const RESPONSIBILITY_RULES = [
        'department_code' => ['nullable', 'string'],
        'custodian_email' => ['nullable', 'email', 'max:255'],
    ];

    /** @return list<ImportColumn> F-09: department (dropdown) and custodian (member email) */
    private static function responsibilityColumns(): array
    {
        return [
            // Optional: files downloaded before F-09 lack them and still upload.
            new ImportColumn('department_code', 'Departemen', hint: 'Pilih departemen dari daftar. Boleh dikosongkan.', list: 'Departemen', width: 30, optional: true),
            new ImportColumn('custodian_email', 'Penanggung Jawab (email)', hint: 'Email anggota aktif organisasi yang memegang aset. Boleh dikosongkan.', width: 30, optional: true),
        ];
    }

    /**
     * Department and custodian of a spreadsheet row as ids, only for the columns the file has (an
     * older file without them leaves both as they are). The custodian is found by email among this
     * organization's members whatever their status: AssetService rejects a newly chosen inactive
     * member, while an unchanged one who has since left stays.
     *
     * @param  array<string, mixed>  $row
     * @return array{department_id?: string|null, custodian_user_id?: string|null}
     */
    private function importResponsibility(array $row): array
    {
        $result = [];
        if (array_key_exists('department_code', $row)) {
            $result['department_id'] = SpreadsheetImport::idByCode(Department::query(), $row['department_code'], 'Departemen');
        }
        if (! array_key_exists('custodian_email', $row)) {
            return $result;
        }
        $email = $row['custodian_email'];
        $custodian = $email === null ? null : OrganizationMember::query()
            ->join('users', 'users.id', '=', 'organization_members.user_id')
            ->whereRaw('lower(users.email) = ?', [mb_strtolower(trim($email))])
            ->value('organization_members.user_id');
        if ($email !== null && $custodian === null) {
            throw ApiException::unprocessable('INVALID_REFERENCE', "Anggota dengan email '{$email}' tidak ditemukan di organisasi ini.");
        }

        return [...$result, 'custodian_user_id' => $custodian];
    }

    /** @return list<array{value: string, label: string}> */
    private static function conditionOptions(): array
    {
        $condition = array_filter(CategoryAttributeService::DEFAULT_ATTRIBUTES['status_condition'],
            fn (array $a) => $a['label'] === CategoryAttributeService::CONDITION_LABEL);

        return reset($condition)['options'];
    }

    /**
     * The condition column as a category field value: {key: option value}. A value that is not an
     * option is passed on as is, so the field's own validation reports it.
     *
     * @return array<string, string>
     */
    private function importConditionValue(string $categoryId, ?string $value): array
    {
        $attribute = $this->attributes->conditionAttribute($categoryId);
        if ($attribute === null || $value === null) {
            return [];
        }
        foreach ($attribute->options as $option) {
            if (strcasecmp($option['label'], $value) === 0 || strcasecmp($option['value'], $value) === 0) {
                return [$attribute->key => $option['value']];
            }
        }

        return [$attribute->key => $value];
    }

    /** The status column accepts the key (in_use) or its label (Aktif); empty means in use. */
    private function importStatus(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $key = str_replace(' ', '_', strtolower($value));
        $byLabel = array_search(strtolower($value), array_map('strtolower', AssetStatus::LABELS), true)
            ?: (AssetStatus::FORMER_LABELS[strtolower(trim($value))] ?? false);

        return in_array($key, AssetStatus::ALL, true) ? $key : ($byLabel ?: $value);
    }

    /** Full history of one asset, newest first. */
    public function transactions(Request $request, Asset $asset): AnonymousResourceCollection
    {
        $history = $asset->transactions()->with(['fromLocation', 'toLocation', 'creator', 'incomingTransaction:id,outgoing_transaction_id,transaction_date'])
            ->orderByDesc('seq')
            ->paginate(ListQuery::perPage($request, 10));

        return AssetTransactionResource::collection($history);
    }

    /**
     * Timeline of one asset: transactions plus edits to its data, newest first.
     * Transactions are left out for users without transaction.view.
     */
    public function timeline(Request $request, Asset $asset, AssetTimeline $timeline): JsonResponse
    {
        return response()->json(['data' => $timeline->for($asset, $request, $this->tenancy->tenant()->can('transaction.view'))]);
    }

    /** @return array<string, list<mixed>> */
    private function descriptiveRules(bool $creating): array
    {
        $required = $creating ? ['required'] : ['sometimes', 'required'];
        $optional = $creating ? ['nullable'] : ['sometimes', 'nullable'];

        return [
            'name' => [...$required, 'string', 'max:150'],
            'serial_number' => [...$optional, 'string', 'max:100'],
            'category_id' => [...$required, 'uuid'],
            'acquisition_date' => [...$optional, 'date_format:Y-m-d', 'before_or_equal:today'],
            'acquisition_cost' => [...$optional, 'numeric', 'min:0', 'max:9999999999999999'],
            'description' => [...$optional, 'string', 'max:2000'],
            // Category fields, {key: value}; validated against the category by the service.
            'attributes' => [...$optional, 'array'],
            // Optional category fields this asset does not use, and fields only it has; checked by the service.
            'hidden_attributes' => [...$optional, 'array', 'max:100'],
            'hidden_attributes.*' => ['string', 'max:60'],
            'extra_attributes' => [...$optional, 'array', 'max:20'],
            'extra_attributes.*' => ['array:label,data_type,value'],
            'extra_attributes.*.label' => ['nullable', 'string', 'max:100'],
            'extra_attributes.*.data_type' => ['nullable', 'string', 'max:16'],
            // F-09; membership and status are checked by the service.
            'custodian_user_id' => [...$optional, 'uuid'],
            'department_id' => [...$optional, 'uuid'],
        ];
    }

    /**
     * Active members to pick as custodian (F-09): name or email search, at most
     * MAX_CUSTODIAN_OPTIONS. Only for users who may set it (asset.create or asset.update).
     */
    public function custodianOptions(Request $request): JsonResponse
    {
        $tenant = $this->tenancy->tenant();
        if (! $tenant->can('asset.create') && ! $tenant->can('asset.update')) {
            throw ApiException::forbidden('FORBIDDEN', 'Anda tidak memiliki izin untuk tindakan ini.');
        }
        $search = $request->validate(['search' => ['nullable', 'string', 'max:100']])['search'] ?? null;

        $members = OrganizationMember::query()->where('organization_members.status', User::STATUS_ACTIVE)
            ->join('users', 'users.id', '=', 'organization_members.user_id')
            ->where('users.status', User::STATUS_ACTIVE)
            ->when($search, fn ($q, $term) => $q->where(fn ($w) => $w
                ->where('users.name', 'ilike', ListQuery::like($term))
                ->orWhere('users.email', 'ilike', ListQuery::like($term))))
            ->with('homeDepartment:id,code,name')
            ->orderBy('users.name')->orderBy('users.id')
            ->limit(self::MAX_CUSTODIAN_OPTIONS)
            ->get(['organization_members.*', 'users.name as user_name', 'users.email as user_email']);

        return response()->json(['data' => $members->map(fn (OrganizationMember $m) => [
            'id' => $m->user_id,
            'name' => $m->getAttribute('user_name'),
            'email' => $m->getAttribute('user_email'),
            'job_title' => $m->job_title,
            'home_department' => $m->homeDepartment === null ? null : Ref::of($m->homeDepartment),
        ])->values()]);
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
