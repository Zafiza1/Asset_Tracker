<?php

namespace App\Http\Controllers\Tenant;

use App\Domain\Organization\Models\Vehicle;
use App\Domain\Organization\Services\DeliveryMasterService;
use App\Domain\Shared\Masters\CodeGenerator;
use App\Domain\Shared\Masters\MasterStatus;
use App\Domain\Shared\Tenancy\Tenancy;
use App\Http\Controllers\Controller;
use App\Http\Resources\VehicleResource;
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
 * Master Data › Kendaraan: the vehicles deliveries are made with. Whether a vehicle is Tersedia or
 * Sedang Digunakan follows from the transactions (Vehicle::withUsage()), so it is never edited here.
 */
class VehicleController extends Controller
{
    /** Mirrors the frontend: the checked ids travel in the download URL. */
    private const MAX_EDIT_SELECTION = 500;

    public function __construct(private readonly DeliveryMasterService $masters) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $request->validate([
            'type' => ['nullable', Rule::in(array_keys(Vehicle::TYPES))],
            'usage' => ['nullable', Rule::in(array_keys(Vehicle::USAGES))],
        ]);
        $query = Vehicle::query()->withUsage()
            ->when($request->query('type'), fn ($q, $type) => $q->where('type', $type))
            ->when($request->query('usage'), fn ($q, $usage) => $q->whereUsage($usage));

        return VehicleResource::collection(MasterRequests::list($query, $request,
            ['code' => 'code', 'name' => 'name', 'plate_number' => 'plate_number', 'type' => 'type', 'year' => 'year',
                'stnk_expires_on' => 'stnk_expires_on', 'kir_expires_on' => 'kir_expires_on', 'status' => 'status', 'created_at' => 'created_at'],
            'name', ['code', 'name', 'plate_number', 'chassis_number', 'engine_number']));
    }

    public function store(Request $request, Tenancy $tenancy): VehicleResource
    {
        MasterRequests::normalizeCode($request);
        $this->normalizePlate($request);
        $data = $request->validate([
            'code' => [...MasterRequests::codeRules(optional: true), Rule::unique('vehicles', 'code')->where('organization_id', $tenancy->organizationId())],
            ...$this->rules(creating: true, tenancy: $tenancy),
        ]);
        $data['code'] = ($data['code'] ?? null) ?: CodeGenerator::fromName($data['name'], 'vehicles', $tenancy->organizationId());

        return new VehicleResource($this->masters->createVehicle($data));
    }

    public function show(Vehicle $vehicle): VehicleResource
    {
        return new VehicleResource($vehicle);
    }

    /** The descriptive data only: the status follows the transactions. */
    public function update(Request $request, Vehicle $vehicle, Tenancy $tenancy): VehicleResource
    {
        $this->normalizePlate($request);
        $data = $request->validate($this->rules(creating: false, tenancy: $tenancy, except: $vehicle));

        return new VehicleResource($this->masters->updateVehicle($vehicle, $data));
    }

    public function archive(Request $request, Vehicle $vehicle): VehicleResource
    {
        $data = $request->validate(MasterRequests::archiveRules());

        return new VehicleResource($this->masters->archiveVehicle($vehicle, $data['reason'] ?? null));
    }

    public function restore(Vehicle $vehicle): VehicleResource
    {
        return new VehicleResource($this->masters->restoreVehicle($vehicle));
    }

    /** Registers vehicles from an .xlsx (see the template); codes are generated from the name when empty. */
    public function import(Request $request, Tenancy $tenancy): JsonResponse
    {
        $columns = $this->importColumns();
        $rows = SpreadsheetImport::read($request, $columns);

        $imported = SpreadsheetImport::run($rows, function (array $row) use ($tenancy, $columns) {
            $row['code'] = SpreadsheetImport::code($row['code']);
            $row = $this->fromSheet($row);
            $data = Validator::make($row, [
                'code' => [...MasterRequests::codeRules(optional: true), Rule::unique('vehicles', 'code')->where('organization_id', $tenancy->organizationId())],
                ...$this->rules(creating: true, tenancy: $tenancy),
            ], SpreadsheetImport::messages(), SpreadsheetImport::attributes($columns))->validate();
            $data['code'] = ($data['code'] ?? null) ?: CodeGenerator::fromName($data['name'], 'vehicles', $tenancy->organizationId());
            $this->masters->createVehicle($data);
        });

        return response()->json(['data' => ['imported' => $imported]]);
    }

    public function downloadTemplate(): BinaryFileResponse
    {
        return SpreadsheetImport::template('template_kendaraan.xlsx', 'Template Import Kendaraan', $this->importColumns(), $this->lists(), [
            'Nomor polisi harus unik. Kode boleh dikosongkan: dibuat otomatis dari nama.',
        ]);
    }

    /**
     * The vehicles (not archived) as an .xlsx to edit and upload to importEdits(); `ids`
     * (comma-separated) limits it to the vehicles checked in the list.
     */
    public function downloadEditSheet(Request $request): BinaryFileResponse
    {
        $request->validate(['ids' => ['nullable', 'string', 'max:'.(self::MAX_EDIT_SELECTION * 37)]]);
        $ids = array_values(array_unique(array_filter(explode(',', (string) $request->query('ids')))));
        $request->merge(['id_list' => $ids])->validate([
            'id_list' => ['array', 'max:'.self::MAX_EDIT_SELECTION],
            'id_list.*' => ['uuid'],
        ]);

        $query = Vehicle::query()->withUsage()->where('status', '!=', MasterStatus::ARCHIVED)
            ->when($ids !== [], fn ($q) => $q->whereIn('id', $ids));
        SpreadsheetImport::assertExportable($query->count());

        $rows = $query->orderBy('name')->get()->map(fn (Vehicle $v) => [
            'id' => $v->id,
            'code' => $v->code,
            'name' => $v->name,
            'plate_number' => $v->plate_number,
            // An old kind (mobil, motor, ...) is left empty: it has to be set to Truk or Pick Up.
            'type' => Vehicle::TYPES[$v->type] ?? null,
            'year' => $v->year,
            'chassis_number' => $v->chassis_number,
            'engine_number' => $v->engine_number,
            'fuel' => $v->fuel === null ? null : (Vehicle::FUELS[$v->fuel] ?? null),
            'capacity' => $v->capacity,
            'stnk_expires_on' => $v->stnk_expires_on?->toDateString(),
            'kir_expires_on' => $v->kir_expires_on?->toDateString(),
            'usage' => Vehicle::USAGES[$v->openDeliveryNumber() === null ? Vehicle::AVAILABLE : Vehicle::IN_USE],
        ]);

        return SpreadsheetImport::editSheet('edit_kendaraan.xlsx', 'Multiple Edit Kendaraan', $this->editColumns(), $rows, $this->lists(), [
            'Status (Tersedia / Sedang Digunakan) diatur sistem dari transaksi dan tidak dapat diubah di sini.',
        ]);
    }

    /** Applies an edited sheet from downloadEditSheet(): rows are matched by the hidden id, unchanged ones skipped. */
    public function importEdits(Request $request, Tenancy $tenancy): JsonResponse
    {
        $columns = $this->editColumns();
        $rows = SpreadsheetImport::read($request, $columns, edit: true);
        $seen = [];

        $updated = SpreadsheetImport::run($rows, function (array $row) use ($tenancy, $columns, &$seen) {
            $vehicle = SpreadsheetImport::existing(Vehicle::query()->where('status', '!=', MasterStatus::ARCHIVED), $row['id'], $seen);
            $data = Validator::make($this->fromSheet($row), $this->rules(creating: true, tenancy: $tenancy, except: $vehicle),
                SpreadsheetImport::messages(), SpreadsheetImport::attributes($columns))->validate();
            $changes = SpreadsheetImport::changes($vehicle, $data);
            if ($changes === []) {
                return false;
            }
            $this->masters->updateVehicle($vehicle, $changes);
        });

        return response()->json(['data' => ['updated' => $updated]]);
    }

    /** Plates are kept in capitals with single spaces ("L 9123 UA"). */
    private function normalizePlate(Request $request): void
    {
        if (is_string($request->input('plate_number'))) {
            $request->merge(['plate_number' => self::plate($request->input('plate_number'))]);
        }
    }

    private static function plate(string $value): string
    {
        return preg_replace('/\s+/', ' ', mb_strtoupper(trim($value)));
    }

    /**
     * A spreadsheet row in the form of the API: kinds and fuels by key (the sheet shows labels), the
     * plate written as normalizePlate() keeps it.
     *
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function fromSheet(array $row): array
    {
        $key = fn (array $labels, mixed $value) => $value === null ? null
            : (array_search(mb_strtolower(trim((string) $value)), array_map('mb_strtolower', $labels), true) ?: (array_key_exists((string) $value, $labels) ? (string) $value : $value));
        $row['type'] = $key(Vehicle::TYPES, $row['type'] ?? null);
        $row['fuel'] = $key(Vehicle::FUELS, $row['fuel'] ?? null);
        if (is_string($row['plate_number'] ?? null)) {
            $row['plate_number'] = self::plate($row['plate_number']);
        }

        return $row;
    }

    /** @return array<string, list<string>> dropdowns of the spreadsheets */
    private function lists(): array
    {
        return ['Jenis' => array_values(Vehicle::TYPES), 'Bahan Bakar' => array_values(Vehicle::FUELS)];
    }

    /** @return list<ImportColumn> */
    private function fieldColumns(bool $editing): array
    {
        $clear = $editing ? ' Kosongkan untuk menghapus.' : ' Boleh dikosongkan.';

        return [
            new ImportColumn('name', 'Nama / Merk', required: true, hint: 'Nama kendaraan, maksimal 150 karakter. Contoh: Truk Hino Dutro', width: 30),
            new ImportColumn('plate_number', 'Nomor Polisi', required: true, hint: 'Harus unik. Contoh: L 9123 UA', width: 16),
            new ImportColumn('type', 'Jenis', required: true, hint: 'Truk atau Pick Up.', list: 'Jenis', width: 14),
            new ImportColumn('year', 'Tahun Pembuatan', hint: 'Contoh: 2022.'.$clear, type: ImportColumn::NUMBER, width: 16),
            new ImportColumn('chassis_number', 'Nomor Rangka', hint: 'Maksimal 50 karakter.'.$clear, width: 22),
            new ImportColumn('engine_number', 'Nomor Mesin', hint: 'Maksimal 50 karakter.'.$clear, width: 22),
            new ImportColumn('fuel', 'Bahan Bakar', hint: 'Pilih dari daftar.'.$clear, list: 'Bahan Bakar', width: 14),
            new ImportColumn('capacity', 'Kapasitas Muatan (kg)', hint: 'Angka saja. Contoh: 4000.'.$clear, type: ImportColumn::NUMBER, width: 20),
            // Text, not a date column: Excel's date check of the template only allows dates up to today.
            new ImportColumn('stnk_expires_on', 'STNK Berlaku Sampai', hint: 'Format YYYY-MM-DD. Contoh: 2027-08-17.'.$clear, width: 20),
            new ImportColumn('kir_expires_on', 'KIR Berlaku Sampai', hint: 'Format YYYY-MM-DD. Contoh: 2027-02-01.'.$clear, width: 20),
        ];
    }

    /** @return list<ImportColumn> */
    private function importColumns(): array
    {
        $fields = $this->fieldColumns(editing: false);

        return [
            $fields[0],
            new ImportColumn('code', 'Kode', hint: 'Kode unik (huruf, angka, - _ .). Jika kosong, dibuat otomatis dari nama.', width: 18),
            ...array_slice($fields, 1),
        ];
    }

    /** @return list<ImportColumn> the code cannot be changed and the status follows the transactions: both are shown for reference only */
    private function editColumns(): array
    {
        return [
            new ImportColumn('code', 'Kode (info)', hint: 'Kode kendaraan, tidak dapat diubah.', width: 18, readOnly: true),
            ...$this->fieldColumns(editing: true),
            new ImportColumn('usage', 'Status (info)', hint: 'Tersedia atau Sedang Digunakan, diatur sistem dari transaksi.', width: 18, readOnly: true),
            SpreadsheetImport::idColumn(),
        ];
    }

    /** @return array<string, list<mixed>> */
    private function rules(bool $creating, Tenancy $tenancy, ?Vehicle $except = null): array
    {
        $required = $creating ? ['required'] : ['sometimes', 'required'];
        $optional = $creating ? ['nullable'] : ['sometimes', 'nullable'];
        $plateTaken = function (string $attribute, mixed $value, \Closure $fail) use ($tenancy, $except) {
            $taken = Vehicle::query()->where('organization_id', $tenancy->organizationId())
                ->whereRaw('upper(plate_number) = ?', [mb_strtoupper((string) $value)])
                ->when($except, fn ($q) => $q->whereKeyNot($except->id))->exists();
            if ($taken) {
                $fail('Nomor polisi ini sudah terdaftar.');
            }
        };

        return [
            'name' => [...$required, 'string', 'max:150'],
            'plate_number' => [...$required, 'string', 'max:20', $plateTaken],
            'type' => [...$required, Rule::in(array_keys(Vehicle::TYPES))],
            'year' => [...$optional, 'integer', 'between:1950,'.((int) date('Y') + 1)],
            'chassis_number' => [...$optional, 'string', 'max:50'],
            'engine_number' => [...$optional, 'string', 'max:50'],
            'fuel' => [...$optional, Rule::in(array_keys(Vehicle::FUELS))],
            'capacity' => [...$optional, 'numeric', 'min:0', 'max:9999999999'],
            'stnk_expires_on' => [...$optional, 'date_format:Y-m-d'],
            'kir_expires_on' => [...$optional, 'date_format:Y-m-d'],
        ];
    }
}
