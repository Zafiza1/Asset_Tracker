<?php

namespace App\Http\Controllers\Tenant;

use App\Domain\Organization\Models\Customer;
use App\Domain\Organization\Services\DeliveryMasterService;
use App\Domain\Shared\Masters\CodeGenerator;
use App\Domain\Shared\Masters\MasterStatus;
use App\Domain\Shared\Tenancy\Tenancy;
use App\Http\Controllers\Controller;
use App\Http\Resources\CustomerResource;
use App\Http\Support\ImportColumn;
use App\Http\Support\MasterRequests;
use App\Http\Support\SpreadsheetImport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/** Master Data › Customer: where cylinders are delivered to and picked up from. */
class CustomerController extends Controller
{
    /** Mirrors the frontend: the checked ids travel in the download URL. */
    private const MAX_EDIT_SELECTION = 500;

    public function __construct(private readonly DeliveryMasterService $masters) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        return CustomerResource::collection(MasterRequests::list(Customer::query(), $request,
            ['code' => 'code', 'name' => 'name', 'address' => 'address', 'status' => 'status', 'created_at' => 'created_at'],
            'name', ['code', 'name', 'address', 'contact_name']));
    }

    public function store(Request $request, Tenancy $tenancy): CustomerResource
    {
        MasterRequests::normalizeCode($request);
        $data = $request->validate([
            'code' => [...MasterRequests::codeRules(optional: true), Rule::unique('customers', 'code')->where('organization_id', $tenancy->organizationId())],
            ...$this->rules(creating: true),
        ]);
        $data['code'] = ($data['code'] ?? null) ?: CodeGenerator::fromName($data['name'], 'customers', $tenancy->organizationId());

        return new CustomerResource($this->masters->createCustomer($data));
    }

    public function show(Customer $customer): CustomerResource
    {
        return new CustomerResource($customer);
    }

    public function update(Request $request, Customer $customer): CustomerResource
    {
        $data = $request->validate([...$this->rules(creating: false), 'status' => ['sometimes', Rule::in(MasterStatus::EDITABLE)]]);

        return new CustomerResource($this->masters->updateCustomer($customer, $data));
    }

    public function archive(Request $request, Customer $customer): CustomerResource
    {
        $data = $request->validate(MasterRequests::archiveRules());

        return new CustomerResource($this->masters->archiveCustomer($customer, $data['reason'] ?? null));
    }

    public function restore(Customer $customer): CustomerResource
    {
        return new CustomerResource($this->masters->restoreCustomer($customer));
    }

    /** Registers customers from an .xlsx (see the template); codes are generated from the name when empty. */
    public function import(Request $request, Tenancy $tenancy): JsonResponse
    {
        $columns = $this->importColumns();
        $rows = SpreadsheetImport::read($request, $columns);

        $imported = SpreadsheetImport::run($rows, function (array $row) use ($tenancy, $columns) {
            $row['code'] = SpreadsheetImport::code($row['code']);
            $data = Validator::make($row, [
                'code' => [...MasterRequests::codeRules(optional: true), Rule::unique('customers', 'code')->where('organization_id', $tenancy->organizationId())],
                ...$this->rules(creating: true),
            ], SpreadsheetImport::messages(), SpreadsheetImport::attributes($columns))->validate();
            $data['code'] = ($data['code'] ?? null) ?: CodeGenerator::fromName($data['name'], 'customers', $tenancy->organizationId());
            $this->masters->createCustomer($data);
        });

        return response()->json(['data' => ['imported' => $imported]]);
    }

    public function downloadTemplate(): BinaryFileResponse
    {
        return SpreadsheetImport::template('template_customer.xlsx', 'Template Import Customer', $this->importColumns(), [], [
            'Kode boleh dikosongkan: dibuat otomatis dari nama.',
        ]);
    }

    /**
     * The customers (not archived) as an .xlsx to edit and upload to importEdits(); `ids`
     * (comma-separated) limits it to the customers checked in the list.
     */
    public function downloadEditSheet(Request $request): BinaryFileResponse
    {
        $request->validate(['ids' => ['nullable', 'string', 'max:'.(self::MAX_EDIT_SELECTION * 37)]]);
        $ids = array_values(array_unique(array_filter(explode(',', (string) $request->query('ids')))));
        $request->merge(['id_list' => $ids])->validate([
            'id_list' => ['array', 'max:'.self::MAX_EDIT_SELECTION],
            'id_list.*' => ['uuid'],
        ]);

        $query = Customer::query()->where('status', '!=', MasterStatus::ARCHIVED)->when($ids !== [], fn ($q) => $q->whereIn('id', $ids));
        SpreadsheetImport::assertExportable($query->count());

        $rows = $query->orderBy('name')->get()->map(fn (Customer $c) => [
            'id' => $c->id,
            'code' => $c->code,
            'name' => $c->name,
            'address' => $c->address,
            'contact_name' => $c->contact_name,
            'phone' => $c->phone,
            'status' => SpreadsheetImport::MASTER_STATUS_LABELS[$c->status] ?? $c->status,
        ]);

        return SpreadsheetImport::editSheet('edit_customer.xlsx', 'Multiple Edit Customer', $this->editColumns(), $rows,
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
            $data = Validator::make($row, [...$this->rules(creating: true), 'status' => ['required', Rule::in(MasterStatus::EDITABLE)]],
                SpreadsheetImport::messages(), SpreadsheetImport::attributes($columns))->validate();

            $customer = SpreadsheetImport::existing(Customer::query()->where('status', '!=', MasterStatus::ARCHIVED), $row['id'], $seen);
            $changes = SpreadsheetImport::changes($customer, $data);
            if ($changes === []) {
                return false;
            }
            $this->masters->updateCustomer($customer, $changes);
        });

        return response()->json(['data' => ['updated' => $updated]]);
    }

    /** @return list<ImportColumn> */
    private function fieldColumns(bool $editing): array
    {
        $clear = $editing ? ' Kosongkan untuk menghapus.' : ' Boleh dikosongkan.';

        return [
            new ImportColumn('address', 'Alamat', hint: 'Alamat pengiriman, maksimal 500 karakter.'.$clear, width: 45),
            new ImportColumn('contact_name', 'Nama Kontak', hint: 'Orang yang dihubungi.'.$clear, width: 24),
            new ImportColumn('phone', 'No HP', hint: 'Contoh: 0812-3456-7890.'.$clear, width: 18),
        ];
    }

    /** @return list<ImportColumn> */
    private function importColumns(): array
    {
        return [
            new ImportColumn('name', 'Nama Customer', required: true, hint: 'Nama customer, maksimal 150 karakter. Contoh: RS Sehat Sentosa', width: 32),
            new ImportColumn('code', 'Kode', hint: 'Kode unik (huruf, angka, - _ .). Jika kosong, dibuat otomatis dari nama.', width: 18),
            ...$this->fieldColumns(editing: false),
        ];
    }

    /** @return list<ImportColumn> the code cannot be changed: shown for reference only */
    private function editColumns(): array
    {
        return [
            new ImportColumn('code', 'Kode (info)', hint: 'Kode customer, tidak dapat diubah.', width: 18, readOnly: true),
            new ImportColumn('name', 'Nama Customer', required: true, hint: 'Nama customer, maksimal 150 karakter.', width: 32),
            ...$this->fieldColumns(editing: true),
            new ImportColumn('status', 'Status', required: true, hint: 'Aktif, atau Nonaktif agar tidak bisa dipilih di transaksi baru.', list: 'Status', width: 14),
            SpreadsheetImport::idColumn(),
        ];
    }

    /** @return array<string, list<mixed>> */
    private function rules(bool $creating): array
    {
        $optional = $creating ? ['nullable'] : ['sometimes', 'nullable'];

        return [
            'name' => [...($creating ? ['required'] : ['sometimes', 'required']), 'string', 'max:150'],
            'address' => [...$optional, 'string', 'max:500'],
            'contact_name' => [...$optional, 'string', 'max:150'],
            'phone' => [...$optional, 'string', 'max:30'],
        ];
    }
}
