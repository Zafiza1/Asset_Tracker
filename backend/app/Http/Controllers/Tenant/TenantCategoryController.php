<?php

namespace App\Http\Controllers\Tenant;

use App\Domain\Shared\Masters\CodeGenerator;
use App\Domain\Shared\Masters\MasterStatus;
use App\Domain\Shared\Tenancy\Tenancy;
use App\Domain\TenantMaster\Models\TenantAssetCategory;
use App\Domain\TenantMaster\Services\TenantCategoryService;
use App\Http\Controllers\Controller;
use App\Http\Resources\TenantCategoryResource;
use App\Http\Support\ImportColumn;
use App\Http\Support\MasterRequests;
use App\Http\Support\SpreadsheetImport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class TenantCategoryController extends Controller
{
    private const RELATIONS = ['parent', 'genericCategory'];

    public function __construct(private readonly TenantCategoryService $categories) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $request->validate(['generic_category_id' => ['nullable', 'uuid']]);
        $query = TenantAssetCategory::query()->with(self::RELATIONS)
            ->withCount(['attributes as attributes_count' => fn ($q) => $q->where('status', MasterStatus::ACTIVE)])
            ->when($request->query('generic_category_id'), fn ($q, $id) => $q->where('generic_category_id', $id));

        return TenantCategoryResource::collection(MasterRequests::list($query, $request,
            ['tree' => 'path', 'code' => 'code', 'name' => 'name', 'status' => 'status'], 'tree'));
    }

    public function store(Request $request, Tenancy $tenancy): TenantCategoryResource
    {
        MasterRequests::normalizeCode($request);
        $data = $request->validate([
            'code' => [...MasterRequests::codeRules(optional: true), Rule::unique('tenant_asset_categories', 'code')->where('organization_id', $tenancy->organizationId())],
            'name' => ['required', 'string', 'max:150'],
            'description' => ['nullable', 'string', 'max:1000'],
            'parent_id' => ['nullable', 'uuid'],
            'generic_category_id' => ['nullable', 'uuid'],
        ]);

        $data['code'] = ($data['code'] ?? null) ?: CodeGenerator::fromName($data['name'], 'tenant_asset_categories', $tenancy->organizationId());

        return new TenantCategoryResource($this->categories->create($data)->load(self::RELATIONS));
    }

    public function show(TenantAssetCategory $tenantCategory): TenantCategoryResource
    {
        return new TenantCategoryResource($tenantCategory->load(self::RELATIONS));
    }

    public function update(Request $request, TenantAssetCategory $tenantCategory): TenantCategoryResource
    {
        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:150'],
            'description' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'parent_id' => ['sometimes', 'nullable', 'uuid'],
            'status' => ['sometimes', Rule::in(MasterStatus::EDITABLE)],
        ]);

        return new TenantCategoryResource($this->categories->update($tenantCategory, $data)->load(self::RELATIONS));
    }

    public function archive(Request $request, TenantAssetCategory $tenantCategory): TenantCategoryResource
    {
        $data = $request->validate(MasterRequests::archiveRules());

        return new TenantCategoryResource($this->categories->archive($tenantCategory, $data['reason'] ?? null)->load(self::RELATIONS));
    }

    public function restore(TenantAssetCategory $tenantCategory): TenantCategoryResource
    {
        return new TenantCategoryResource($this->categories->restore($tenantCategory)->load(self::RELATIONS));
    }

    public function destroy(TenantAssetCategory $tenantCategory): JsonResponse
    {
        $this->categories->destroy($tenantCategory);

        return response()->json(null, 204);
    }

    /**
     * Creates categories from an .xlsx (see the template). A parent may be an existing category
     * or one on an earlier row of the same file; codes are generated from the name when empty.
     */
    public function import(Request $request, Tenancy $tenancy): JsonResponse
    {
        $columns = $this->importColumns();
        $rows = SpreadsheetImport::read($request, $columns);

        $imported = SpreadsheetImport::run($rows, function (array $row) use ($tenancy, $columns) {
            $row['code'] = SpreadsheetImport::code($row['code']);
            $data = Validator::make($row, [
                'code' => [...MasterRequests::codeRules(optional: true), Rule::unique('tenant_asset_categories', 'code')->where('organization_id', $tenancy->organizationId())],
                'name' => ['required', 'string', 'max:150'],
                'parent_code' => ['nullable', 'string'],
                'description' => ['nullable', 'string', 'max:1000'],
            ], SpreadsheetImport::messages(), SpreadsheetImport::attributes($columns))->validate();

            $this->categories->create([
                'code' => $data['code'] ?: CodeGenerator::fromName($data['name'], 'tenant_asset_categories', $tenancy->organizationId()),
                'name' => $data['name'],
                'description' => $data['description'],
                'parent_id' => SpreadsheetImport::idByCode(TenantAssetCategory::query(), $data['parent_code'], 'Kategori induk'),
            ]);
        });

        return response()->json(['data' => ['imported' => $imported]]);
    }

    public function downloadTemplate(): BinaryFileResponse
    {
        return SpreadsheetImport::template('template_kategori_aset.xlsx', 'Template Import Kategori Aset', $this->importColumns(), [
            'Kategori Induk' => SpreadsheetImport::codeList(TenantAssetCategory::query()),
        ], [
            'Untuk subkategori, isi Kategori Induk dengan kategori yang sudah ada (dropdown), atau dengan Kode kategori pada baris di atasnya dalam file ini.',
        ]);
    }

    /** The categories (not archived) in tree order, as an .xlsx to edit and upload to importEdits(). */
    public function downloadEditSheet(): BinaryFileResponse
    {
        $query = TenantAssetCategory::query()->where('status', '!=', MasterStatus::ARCHIVED);
        SpreadsheetImport::assertExportable($query->count());

        $rows = $query->with('parent')->orderBy('path')->get()->map(fn (TenantAssetCategory $c) => [
            'id' => $c->id,
            'name' => $c->name,
            'parent_code' => SpreadsheetImport::codeItem($c->parent),
            'description' => $c->description,
            'status' => SpreadsheetImport::MASTER_STATUS_LABELS[$c->status] ?? $c->status,
        ]);

        return SpreadsheetImport::editSheet('edit_kategori_aset.xlsx', 'Edit Massal Kategori Aset', $this->editColumns(), $rows, [
            'Kategori Induk' => SpreadsheetImport::codeList(TenantAssetCategory::query()),
            'Status' => array_values(SpreadsheetImport::MASTER_STATUS_LABELS),
        ], [
            'Untuk memindah kategori, ganti Kategori Induk. Kategori tidak dapat dipindah ke bawah dirinya sendiri atau subkategorinya.',
        ]);
    }

    /** Applies an edited sheet from downloadEditSheet(): rows are matched by the hidden id, unchanged ones skipped. */
    public function importEdits(Request $request): JsonResponse
    {
        $columns = $this->editColumns();
        $rows = SpreadsheetImport::read($request, $columns);
        $seen = [];

        $updated = SpreadsheetImport::run($rows, function (array $row) use ($columns, &$seen) {
            $row['status'] = SpreadsheetImport::masterStatus($row['status']);
            $data = Validator::make($row, [
                'name' => ['required', 'string', 'max:150'],
                'parent_code' => ['nullable', 'string'],
                'description' => ['nullable', 'string', 'max:1000'],
                'status' => ['required', Rule::in(MasterStatus::EDITABLE)],
            ], SpreadsheetImport::messages(), SpreadsheetImport::attributes($columns))->validate();

            $category = SpreadsheetImport::existing(TenantAssetCategory::query()->where('status', '!=', MasterStatus::ARCHIVED), $row['id'], $seen);
            $changes = SpreadsheetImport::changes($category, [
                'name' => $data['name'],
                'description' => $data['description'],
                'parent_id' => SpreadsheetImport::idByCode(TenantAssetCategory::query(), $data['parent_code'], 'Kategori induk'),
                'status' => $data['status'],
            ]);
            if ($changes === []) {
                return false;
            }
            $this->categories->update($category, $changes);
        });

        return response()->json(['data' => ['updated' => $updated]]);
    }

    /** @return list<ImportColumn> */
    private function importColumns(): array
    {
        return [
            new ImportColumn('name', 'Nama Kategori', required: true, hint: 'Nama kategori, maksimal 150 karakter. Contoh: Perangkat IT', width: 30),
            new ImportColumn('code', 'Kode', hint: 'Kode unik kategori (huruf, angka, - _ .). Jika kosong, dibuat otomatis dari nama.', width: 18),
            new ImportColumn('parent_code', 'Kategori Induk', hint: 'Pilih kategori induk, atau ketik Kode kategori dari baris di atasnya. Kosongkan untuk kategori utama.', list: 'Kategori Induk', strictList: false, width: 34),
            new ImportColumn('description', 'Deskripsi', hint: 'Penjelasan singkat kategori. Boleh dikosongkan.', width: 45),
        ];
    }

    /** @return list<ImportColumn> the code cannot be changed, so it is not shown */
    private function editColumns(): array
    {
        return [
            new ImportColumn('name', 'Nama Kategori', required: true, hint: 'Nama kategori, maksimal 150 karakter.', width: 30),
            new ImportColumn('parent_code', 'Kategori Induk', hint: 'Pilih kategori induk. Kosongkan untuk kategori utama.', list: 'Kategori Induk', width: 34),
            new ImportColumn('description', 'Deskripsi', hint: 'Penjelasan singkat kategori. Kosongkan untuk menghapus.', width: 45),
            new ImportColumn('status', 'Status', required: true, hint: 'Aktif, atau Nonaktif agar tidak bisa dipilih untuk aset baru.', list: 'Status', width: 14),
            SpreadsheetImport::idColumn(),
        ];
    }
}
