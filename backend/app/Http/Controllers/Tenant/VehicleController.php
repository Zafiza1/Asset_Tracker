<?php

namespace App\Http\Controllers\Tenant;

use App\Domain\Organization\Models\Vehicle;
use App\Domain\Organization\Services\DeliveryMasterService;
use App\Domain\Shared\Masters\CodeGenerator;
use App\Domain\Shared\Masters\MasterStatus;
use App\Domain\Shared\Tenancy\Tenancy;
use App\Http\Controllers\Controller;
use App\Http\Resources\VehicleResource;
use App\Http\Support\MasterRequests;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

/** Master Data › Kendaraan: the vehicles deliveries are made with. */
class VehicleController extends Controller
{
    public function __construct(private readonly DeliveryMasterService $masters) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $request->validate(['type' => ['nullable', Rule::in(array_keys(Vehicle::TYPES))]]);
        $query = Vehicle::query()->when($request->query('type'), fn ($q, $type) => $q->where('type', $type));

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

    public function update(Request $request, Vehicle $vehicle, Tenancy $tenancy): VehicleResource
    {
        $this->normalizePlate($request);
        $data = $request->validate([
            ...$this->rules(creating: false, tenancy: $tenancy, except: $vehicle),
            'status' => ['sometimes', Rule::in(MasterStatus::EDITABLE)],
        ]);

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

    /** Plates are kept in capitals with single spaces ("L 9123 UA"). */
    private function normalizePlate(Request $request): void
    {
        if (is_string($request->input('plate_number'))) {
            $request->merge(['plate_number' => preg_replace('/\s+/', ' ', mb_strtoupper(trim($request->input('plate_number'))))]);
        }
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
