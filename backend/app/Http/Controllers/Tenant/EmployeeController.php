<?php

namespace App\Http\Controllers\Tenant;

use App\Domain\Organization\Models\Department;
use App\Domain\Organization\Models\Employee;
use App\Domain\Organization\Services\DeliveryMasterService;
use App\Domain\Shared\Masters\MasterStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\EmployeeResource;
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
 * Master Data › Karyawan. The code is given by the system (DeliveryMasterService), and only Supir
 * and Kernet keep a SIM.
 */
class EmployeeController extends Controller
{
    /** Mirrors the frontend: the checked ids travel in the download URL. */
    private const MAX_EDIT_SELECTION = 500;

    public function __construct(private readonly DeliveryMasterService $masters) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $request->validate(['department_id' => ['nullable', 'uuid']]);
        $query = Employee::query()->with('department')
            ->when($request->query('department_id'), fn ($q, $id) => $q->where('department_id', $id));

        return EmployeeResource::collection(MasterRequests::list($query, $request,
            ['code' => 'code', 'name' => 'name', 'department' => Department::query()->select('name')->whereColumn('departments.id', 'employees.department_id'),
                'status' => 'status', 'created_at' => 'created_at'],
            'name', ['code', 'name', 'phone']));
    }

    public function store(Request $request): EmployeeResource
    {
        return new EmployeeResource($this->masters->createEmployee($request->validate($this->rules(creating: true)))->load('department'));
    }

    public function show(Employee $employee): EmployeeResource
    {
        return new EmployeeResource($employee->load('department'));
    }

    public function update(Request $request, Employee $employee): EmployeeResource
    {
        $data = $request->validate([...$this->rules(creating: false), 'status' => ['sometimes', Rule::in(MasterStatus::EDITABLE)]]);

        return new EmployeeResource($this->masters->updateEmployee($employee, $data)->load('department'));
    }

    public function archive(Request $request, Employee $employee): EmployeeResource
    {
        $data = $request->validate(MasterRequests::archiveRules());

        return new EmployeeResource($this->masters->archiveEmployee($employee, $data['reason'] ?? null)->load('department'));
    }

    public function restore(Employee $employee): EmployeeResource
    {
        return new EmployeeResource($this->masters->restoreEmployee($employee)->load('department'));
    }

    /** Registers employees from an .xlsx (see the template); each gets the next code. */
    public function import(Request $request): JsonResponse
    {
        $columns = $this->importColumns();
        $rows = SpreadsheetImport::read($request, $columns);

        $imported = SpreadsheetImport::run($rows, function (array $row) use ($columns) {
            $data = Validator::make($row, $this->sheetRules(), SpreadsheetImport::messages(), SpreadsheetImport::attributes($columns))->validate();
            $this->masters->createEmployee($this->fromSheet($data));
        });

        return response()->json(['data' => ['imported' => $imported]]);
    }

    public function downloadTemplate(): BinaryFileResponse
    {
        return SpreadsheetImport::template('template_karyawan.xlsx', 'Template Import Karyawan', $this->importColumns(), $this->lists(), [
            'Kode karyawan dibuat otomatis oleh sistem. No SIM dan masa berlakunya hanya disimpan untuk divisi Supir dan Kernet.',
        ]);
    }

    /**
     * The employees (not archived) as an .xlsx to edit and upload to importEdits(); `ids`
     * (comma-separated) limits it to the employees checked in the list.
     */
    public function downloadEditSheet(Request $request): BinaryFileResponse
    {
        $request->validate(['ids' => ['nullable', 'string', 'max:'.(self::MAX_EDIT_SELECTION * 37)]]);
        $ids = array_values(array_unique(array_filter(explode(',', (string) $request->query('ids')))));
        $request->merge(['id_list' => $ids])->validate([
            'id_list' => ['array', 'max:'.self::MAX_EDIT_SELECTION],
            'id_list.*' => ['uuid'],
        ]);

        $query = Employee::query()->with('department')->where('status', '!=', MasterStatus::ARCHIVED)
            ->when($ids !== [], fn ($q) => $q->whereIn('id', $ids));
        SpreadsheetImport::assertExportable($query->count());

        $rows = $query->orderBy('code')->get()->map(fn (Employee $e) => [
            'id' => $e->id,
            'code' => $e->code,
            'name' => $e->name,
            'division' => SpreadsheetImport::codeItem($e->department),
            'phone' => $e->phone,
            'license_number' => $e->license_number,
            'license_expires_on' => $e->license_expires_on?->toDateString(),
            'status' => SpreadsheetImport::MASTER_STATUS_LABELS[$e->status] ?? $e->status,
        ]);

        return SpreadsheetImport::editSheet('edit_karyawan.xlsx', 'Multiple Edit Karyawan', $this->editColumns(), $rows,
            [...$this->lists(), 'Status' => array_values(SpreadsheetImport::MASTER_STATUS_LABELS)], [
                'Kode tidak dapat diubah. No SIM hanya disimpan untuk divisi Supir dan Kernet; untuk divisi lain dikosongkan.',
            ]);
    }

    /** Applies an edited sheet from downloadEditSheet(): rows are matched by the hidden id, unchanged ones skipped. */
    public function importEdits(Request $request): JsonResponse
    {
        $columns = $this->editColumns();
        $rows = SpreadsheetImport::read($request, $columns, edit: true);
        $seen = [];

        $updated = SpreadsheetImport::run($rows, function (array $row) use ($columns, &$seen) {
            $row['status'] = SpreadsheetImport::masterStatus($row['status']);
            $data = Validator::make($row, [...$this->sheetRules(), 'status' => ['required', Rule::in(MasterStatus::EDITABLE)]],
                SpreadsheetImport::messages(), SpreadsheetImport::attributes($columns))->validate();

            $employee = SpreadsheetImport::existing(Employee::query()->where('status', '!=', MasterStatus::ARCHIVED), $row['id'], $seen);
            $values = $this->fromSheet($data);
            // A division without a delivery role keeps no SIM, so its emptied cells are no change.
            if (! Department::query()->whereKey($values['department_id'])->whereNotNull('delivery_role')->exists()) {
                $values['license_number'] = $values['license_expires_on'] = null;
            }
            $changes = SpreadsheetImport::changes($employee, $values);
            if ($changes === []) {
                return false;
            }
            $this->masters->updateEmployee($employee, $changes);
        });

        return response()->json(['data' => ['updated' => $updated]]);
    }

    /**
     * A validated spreadsheet row in the form of the API: the division by its id.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function fromSheet(array $data): array
    {
        $values = [
            'name' => $data['name'],
            'department_id' => SpreadsheetImport::idByCode(Department::query(), $data['division'], 'Divisi'),
            'phone' => $data['phone'],
            'license_number' => $data['license_number'],
            'license_expires_on' => $data['license_expires_on'],
        ];

        return array_key_exists('status', $data) ? [...$values, 'status' => $data['status']] : $values;
    }

    /** @return array<string, list<mixed>> */
    private function sheetRules(): array
    {
        return [
            'name' => ['required', 'string', 'max:150'],
            'division' => ['required', 'string'],
            'phone' => ['nullable', 'string', 'max:30'],
            'license_number' => ['nullable', 'string', 'max:50'],
            'license_expires_on' => ['nullable', 'date_format:Y-m-d'],
        ];
    }

    /** @return array<string, list<string>> dropdowns of the spreadsheets */
    private function lists(): array
    {
        return ['Divisi' => SpreadsheetImport::codeList(Department::query())];
    }

    /** @return list<ImportColumn> */
    private function fieldColumns(bool $editing): array
    {
        $clear = $editing ? ' Kosongkan untuk menghapus.' : ' Boleh dikosongkan.';

        return [
            new ImportColumn('name', 'Nama', required: true, hint: 'Nama karyawan, maksimal 150 karakter.', width: 30),
            new ImportColumn('division', 'Divisi', required: true, hint: 'Pilih divisi dari daftar; divisi sekaligus jabatan karyawan.', list: 'Divisi', width: 30),
            new ImportColumn('phone', 'No HP', hint: 'Contoh: 0812-3456-7890.'.$clear, width: 18),
            new ImportColumn('license_number', 'No SIM', hint: 'Hanya untuk divisi Supir dan Kernet.'.$clear, width: 20),
            // Text, not a date column: Excel's date check of the template only allows dates up to today.
            new ImportColumn('license_expires_on', 'SIM Berlaku Sampai', hint: 'Format YYYY-MM-DD, hanya untuk Supir dan Kernet.'.$clear, width: 20),
        ];
    }

    /** @return list<ImportColumn> */
    private function importColumns(): array
    {
        return $this->fieldColumns(editing: false);
    }

    /** @return list<ImportColumn> the code is the system's: shown for reference only */
    private function editColumns(): array
    {
        return [
            new ImportColumn('code', 'Kode (info)', hint: 'Kode karyawan dari sistem, tidak dapat diubah.', width: 14, readOnly: true),
            ...$this->fieldColumns(editing: true),
            new ImportColumn('status', 'Status', required: true, hint: 'Aktif, atau Nonaktif agar tidak bisa dipilih lagi.', list: 'Status', width: 14),
            SpreadsheetImport::idColumn(),
        ];
    }

    /** @return array<string, list<mixed>> the code is not among them: the system gives it */
    private function rules(bool $creating): array
    {
        $required = $creating ? ['required'] : ['sometimes', 'required'];
        $optional = $creating ? ['nullable'] : ['sometimes', 'nullable'];

        return [
            'name' => [...$required, 'string', 'max:150'],
            'department_id' => [...$required, 'uuid'],
            'phone' => [...$optional, 'string', 'max:30'],
            'license_number' => [...$optional, 'string', 'max:50'],
            'license_expires_on' => [...$optional, 'date_format:Y-m-d'],
        ];
    }
}
