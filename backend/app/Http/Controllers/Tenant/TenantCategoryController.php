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

    public function destroy(TenantAssetCategory $tenantCategory): \Illuminate\Http\JsonResponse
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
}
