<?php

namespace App\Http\Controllers\Platform;

use App\Domain\GenericMaster\Models\GenericCondition;
use App\Domain\GenericMaster\Models\GenericTransactionType;
use App\Domain\GenericMaster\Models\LocationType;
use App\Domain\GenericMaster\Models\Unit;
use App\Domain\Shared\Masters\MasterStatus;
use App\Domain\Shared\Masters\MasterWriter;
use App\Http\Controllers\Controller;
use App\Http\Resources\ReferenceResource;
use App\Http\Support\MasterRequests;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

/**
 * Generic reference data: units, location types, standard conditions, transaction types.
 * These are never deleted, only deactivated. Transaction type codes are fixed by code;
 * only their descriptive fields are editable.
 */
class GenericReferenceController extends Controller
{
    public function __construct(private readonly MasterWriter $writer) {}

    public function units(Request $request): AnonymousResourceCollection
    {
        return ReferenceResource::collection(MasterRequests::list(Unit::query(), $request, ['code' => 'code', 'name' => 'name'], 'name'));
    }

    public function storeUnit(Request $request): ReferenceResource
    {
        return $this->store($request, new Unit, 'units', [
            'name' => ['required', 'string', 'max:100'],
            'symbol' => ['required', 'string', 'max:20'],
            'quantity' => ['nullable', 'string', 'max:50'],
        ], 'unit');
    }

    public function updateUnit(Request $request, Unit $unit): ReferenceResource
    {
        return $this->update($request, $unit, [
            'name' => ['sometimes', 'required', 'string', 'max:100'],
            'symbol' => ['sometimes', 'required', 'string', 'max:20'],
            'quantity' => ['sometimes', 'nullable', 'string', 'max:50'],
        ], 'unit');
    }

    public function locationTypes(Request $request): AnonymousResourceCollection
    {
        return ReferenceResource::collection(MasterRequests::list(LocationType::query(), $request, ['code' => 'code', 'name' => 'name'], 'name'));
    }

    public function storeLocationType(Request $request): ReferenceResource
    {
        return $this->store($request, new LocationType, 'location_types', [
            'name' => ['required', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:1000'],
        ], 'location_type');
    }

    public function updateLocationType(Request $request, LocationType $locationType): ReferenceResource
    {
        return $this->update($request, $locationType, [
            'name' => ['sometimes', 'required', 'string', 'max:100'],
            'description' => ['sometimes', 'nullable', 'string', 'max:1000'],
        ], 'location_type');
    }

    public function conditions(Request $request): AnonymousResourceCollection
    {
        return ReferenceResource::collection(MasterRequests::list(GenericCondition::query(), $request, ['sort_order' => 'sort_order', 'name' => 'name'], 'sort_order'));
    }

    public function storeCondition(Request $request): ReferenceResource
    {
        return $this->store($request, new GenericCondition, 'generic_conditions', [
            'name' => ['required', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:1000'],
            'severity' => ['required', 'integer', 'min:1', 'max:9'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:65535'],
        ], 'generic_condition');
    }

    public function updateCondition(Request $request, GenericCondition $genericCondition): ReferenceResource
    {
        return $this->update($request, $genericCondition, [
            'name' => ['sometimes', 'required', 'string', 'max:100'],
            'description' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'severity' => ['sometimes', 'integer', 'min:1', 'max:9'],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:65535'],
        ], 'generic_condition');
    }

    public function transactionTypes(Request $request): AnonymousResourceCollection
    {
        return ReferenceResource::collection(MasterRequests::list(GenericTransactionType::query(), $request, ['sort_order' => 'sort_order', 'name' => 'name'], 'sort_order'));
    }

    public function updateTransactionType(Request $request, GenericTransactionType $transactionType): ReferenceResource
    {
        return $this->update($request, $transactionType, [
            'name' => ['sometimes', 'required', 'string', 'max:100'],
            'description' => ['sometimes', 'nullable', 'string', 'max:1000'],
        ], 'generic_transaction_type');
    }

    /** @param array<string, list<mixed>> $rules */
    private function store(Request $request, Model $model, string $table, array $rules, string $entity): ReferenceResource
    {
        MasterRequests::normalizeCode($request);
        $data = $request->validate(['code' => [...MasterRequests::codeRules(), Rule::unique($table, 'code')], ...$rules]);

        return new ReferenceResource($this->writer->create($model, [...$data, 'status' => MasterStatus::ACTIVE], "{$entity}.created", array_keys($data)));
    }

    /** @param array<string, list<mixed>> $rules */
    private function update(Request $request, Model $model, array $rules, string $entity): ReferenceResource
    {
        $data = $request->validate([...$rules, 'status' => ['sometimes', Rule::in(MasterStatus::EDITABLE)]]);

        return new ReferenceResource($this->writer->update($model, $data, "{$entity}.updated", [...array_keys($rules), 'status']));
    }
}
