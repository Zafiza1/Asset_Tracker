<?php

namespace App\Http\Controllers\Tenant;

use App\Domain\Organization\Models\Department;
use App\Domain\Organization\Services\OrganizationStructureService;
use App\Domain\Shared\Masters\CodeGenerator;
use App\Domain\Shared\Masters\MasterStatus;
use App\Domain\Shared\Tenancy\Tenancy;
use App\Http\Controllers\Controller;
use App\Http\Resources\DepartmentResource;
use App\Http\Support\ImportColumn;
use App\Http\Support\MasterRequests;
use App\Http\Support\SpreadsheetImport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Master Data › Divisi (departments). In the edition without custodians (PT SIG / PT CDO) a division
 * is also the employees' position: its code is made by the system from the name and its delivery role
 * follows the name (Department::roleFromName), so neither is entered there.
 */
class DepartmentController extends Controller
{
    /** Mirrors the frontend: the checked ids travel in the download URL. */
    private const MAX_EDIT_SELECTION = 500;

    public function __construct(private readonly OrganizationStructureService $structure) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $request->validate(['delivery_role' => ['nullable', Rule::in(array_keys(Department::DELIVERY_ROLES))]]);
        $query = Department::query()->withCount(['employees' => fn ($q) => $q->where('status', '!=', MasterStatus::ARCHIVED)])
            ->when($request->query('delivery_role'), fn ($q, $role) => $q->where('delivery_role', $role));

        return DepartmentResource::collection(MasterRequests::list($query, $request,
            ['code' => 'code', 'name' => 'name', 'delivery_role' => 'delivery_role', 'status' => 'status', 'created_at' => 'created_at'], 'name'));
    }

    public function store(Request $request, Tenancy $tenancy): DepartmentResource
    {
        MasterRequests::normalizeCode($request);
        $data = $request->validate([
            'code' => [...MasterRequests::codeRules(optional: true), Rule::unique('departments', 'code')->where('organization_id', $tenancy->organizationId())],
            'name' => ['required', 'string', 'max:150'],
            'delivery_role' => ['nullable', Rule::in(array_keys(Department::DELIVERY_ROLES))],
        ]);

        return new DepartmentResource($this->structure->createDepartment($this->forCreate($data, $tenancy)));
    }

    public function show(Department $department): DepartmentResource
    {
        return new DepartmentResource($department);
    }

    public function update(Request $request, Department $department): DepartmentResource
    {
        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:150'],
            'delivery_role' => ['sometimes', 'nullable', Rule::in(array_keys(Department::DELIVERY_ROLES))],
            'status' => ['sometimes', Rule::in(MasterStatus::EDITABLE)],
        ]);

        return new DepartmentResource($this->structure->updateDepartment($department, $this->forUpdate($data)));
    }

    public function archive(Request $request, Department $department): DepartmentResource
    {
        $data = $request->validate(MasterRequests::archiveRules());

        return new DepartmentResource($this->structure->archiveDepartment($department, $data['reason'] ?? null));
    }

    public function restore(Department $department): DepartmentResource
    {
        return new DepartmentResource($this->structure->restoreDepartment($department));
    }

    /** Creates divisions from an .xlsx (see the template). */
    public function import(Request $request, Tenancy $tenancy): JsonResponse
    {
        $columns = $this->importColumns();
        $rows = SpreadsheetImport::read($request, $columns);

        $imported = SpreadsheetImport::run($rows, function (array $row) use ($tenancy, $columns) {
            $row['code'] = SpreadsheetImport::code($row['code'] ?? null);
            $data = Validator::make($row, [
                'code' => [...MasterRequests::codeRules(optional: true), Rule::unique('departments', 'code')->where('organization_id', $tenancy->organizationId())],
                'name' => ['required', 'string', 'max:150'],
            ], SpreadsheetImport::messages(), SpreadsheetImport::attributes($columns))->validate();
            $this->structure->createDepartment($this->forCreate($data, $tenancy));
        });

        return response()->json(['data' => ['imported' => $imported]]);
    }

    public function downloadTemplate(): BinaryFileResponse
    {
        return SpreadsheetImport::template('template_divisi.xlsx', 'Template Import Divisi', $this->importColumns(), [], [
            $this->divisions()
                ? 'Kode divisi dibuat otomatis oleh sistem dari nama. Divisi bernama Supir atau Kernet otomatis menjadi kru pengiriman.'
                : 'Kode boleh dikosongkan: dibuat otomatis dari nama.',
        ]);
    }

    /**
     * The divisions (not archived) as an .xlsx to edit and upload to importEdits(); `ids`
     * (comma-separated) limits it to the divisions checked in the list.
     */
    public function downloadEditSheet(Request $request): BinaryFileResponse
    {
        $request->validate(['ids' => ['nullable', 'string', 'max:'.(self::MAX_EDIT_SELECTION * 37)]]);
        $ids = array_values(array_unique(array_filter(explode(',', (string) $request->query('ids')))));
        $request->merge(['id_list' => $ids])->validate([
            'id_list' => ['array', 'max:'.self::MAX_EDIT_SELECTION],
            'id_list.*' => ['uuid'],
        ]);

        $query = Department::query()->where('status', '!=', MasterStatus::ARCHIVED)->when($ids !== [], fn ($q) => $q->whereIn('id', $ids));
        SpreadsheetImport::assertExportable($query->count());

        $rows = $query->orderBy('name')->get()->map(fn (Department $d) => [
            'id' => $d->id,
            'code' => $d->code,
            'name' => $d->name,
            'status' => SpreadsheetImport::MASTER_STATUS_LABELS[$d->status] ?? $d->status,
        ]);

        return SpreadsheetImport::editSheet('edit_divisi.xlsx', 'Multiple Edit Divisi', $this->editColumns(), $rows,
            ['Status' => array_values(SpreadsheetImport::MASTER_STATUS_LABELS)], ['Kode tidak dapat diubah.']);
    }

    /** Applies an edited sheet from downloadEditSheet(): rows are matched by the hidden id, unchanged ones skipped. */
    public function importEdits(Request $request): JsonResponse
    {
        $columns = $this->editColumns();
        $rows = SpreadsheetImport::read($request, $columns, edit: true);
        $seen = [];

        $updated = SpreadsheetImport::run($rows, function (array $row) use ($columns, &$seen) {
            $row['status'] = SpreadsheetImport::masterStatus($row['status']);
            $data = Validator::make($row, [
                'name' => ['required', 'string', 'max:150'],
                'status' => ['required', Rule::in(MasterStatus::EDITABLE)],
            ], SpreadsheetImport::messages(), SpreadsheetImport::attributes($columns))->validate();

            $department = SpreadsheetImport::existing(Department::query()->where('status', '!=', MasterStatus::ARCHIVED), $row['id'], $seen);
            $changes = SpreadsheetImport::changes($department, $this->forUpdate(['name' => $data['name'], 'status' => $data['status']]));
            if ($changes === []) {
                return false;
            }
            $this->structure->updateDepartment($department, $changes);
        });

        return response()->json(['data' => ['updated' => $updated]]);
    }

    /** Whether this edition has Divisi (code and delivery role from the system) rather than Departemen. */
    private function divisions(): bool
    {
        return ! config('edition.custodians');
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function forCreate(array $data, Tenancy $tenancy): array
    {
        if ($this->divisions()) {
            $data['code'] = null;
            $data['delivery_role'] = Department::roleFromName($data['name']);
        }
        $data['code'] = ($data['code'] ?? null) ?: CodeGenerator::fromName($data['name'], 'departments', $tenancy->organizationId());

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function forUpdate(array $data): array
    {
        if (! $this->divisions()) {
            return $data;
        }
        unset($data['delivery_role']);

        return array_key_exists('name', $data) ? [...$data, 'delivery_role' => Department::roleFromName($data['name'])] : $data;
    }

    /** @return list<ImportColumn> */
    private function importColumns(): array
    {
        return [
            new ImportColumn('name', 'Nama Divisi', required: true, hint: 'Nama divisi, maksimal 150 karakter. Contoh: Petugas Gudang', width: 30),
            ...($this->divisions() ? [] : [new ImportColumn('code', 'Kode', hint: 'Kode unik (huruf, angka, - _ .). Jika kosong, dibuat otomatis dari nama.', width: 18)]),
        ];
    }

    /** @return list<ImportColumn> the code cannot be changed: shown for reference only */
    private function editColumns(): array
    {
        return [
            new ImportColumn('code', 'Kode (info)', hint: 'Kode divisi, tidak dapat diubah.', width: 18, readOnly: true),
            new ImportColumn('name', 'Nama Divisi', required: true, hint: 'Nama divisi, maksimal 150 karakter.', width: 30),
            new ImportColumn('status', 'Status', required: true, hint: 'Aktif, atau Nonaktif agar tidak bisa dipilih untuk karyawan.', list: 'Status', width: 14),
            SpreadsheetImport::idColumn(),
        ];
    }
}
