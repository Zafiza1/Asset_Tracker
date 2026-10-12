<?php

namespace App\Http\Controllers\Tenant;

use App\Domain\GenericMaster\Models\GenericAssetCategory;
use App\Domain\GenericMaster\Models\GenericAssetDefinition;
use App\Domain\GenericMaster\Models\GenericCondition;
use App\Domain\GenericMaster\Models\GenericDefinitionVersion;
use App\Domain\GenericMaster\Models\GenericTransactionType;
use App\Domain\GenericMaster\Models\LocationType;
use App\Domain\GenericMaster\Models\Unit;
use App\Http\Controllers\Controller;
use App\Http\Resources\GenericCategoryResource;
use App\Http\Resources\GenericDefinitionResource;
use App\Http\Resources\GenericVersionResource;
use App\Http\Resources\ReferenceResource;
use App\Http\Support\MasterRequests;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Read-only view of the Generic Master for organizations. Shared by all tenants; draft
 * versions are platform-internal and never visible here (404).
 */
class GenericCatalogController extends Controller
{
    private const PUBLISHED = [GenericDefinitionVersion::RELEASED, GenericDefinitionVersion::DEPRECATED];

    public function categories(Request $request): AnonymousResourceCollection
    {
        return GenericCategoryResource::collection(MasterRequests::list(GenericAssetCategory::query(), $request,
            ['tree' => 'path', 'sort_order' => 'sort_order', 'code' => 'code', 'name' => 'name'], 'sort_order'));
    }

    /** Definitions that can be adopted: at least one released version. */
    public function definitions(Request $request): AnonymousResourceCollection
    {
        $request->validate(['category_id' => ['nullable', 'uuid']]);
        $query = GenericAssetDefinition::query()->with(['category', 'currentVersion'])
            ->whereHas('versions', fn ($q) => $q->where('status', GenericDefinitionVersion::RELEASED))
            ->when($request->query('category_id'), fn ($q, $id) => $q->where('category_id', $id));

        return GenericDefinitionResource::collection(MasterRequests::list($query, $request, ['code' => 'code', 'name' => 'name'], 'name'));
    }

    public function definition(GenericAssetDefinition $genericDefinition): GenericDefinitionResource
    {
        return new GenericDefinitionResource($genericDefinition->load([
            'category', 'currentVersion',
            'versions' => fn ($q) => $q->whereIn('status', self::PUBLISHED)->withCount('attributeDefinitions')->orderByDesc('version_number'),
        ]));
    }

    public function version(GenericDefinitionVersion $genericVersion): GenericVersionResource
    {
        if (! in_array($genericVersion->status, self::PUBLISHED, true)) {
            throw (new ModelNotFoundException)->setModel(GenericDefinitionVersion::class);
        }

        return new GenericVersionResource($genericVersion->load(['definition', 'attributeDefinitions.unit']));
    }

    public function units(Request $request): AnonymousResourceCollection
    {
        return ReferenceResource::collection(MasterRequests::list(Unit::query(), $request, ['code' => 'code', 'name' => 'name'], 'name'));
    }

    public function locationTypes(Request $request): AnonymousResourceCollection
    {
        return ReferenceResource::collection(MasterRequests::list(LocationType::query(), $request, ['code' => 'code', 'name' => 'name'], 'name'));
    }

    public function conditions(Request $request): AnonymousResourceCollection
    {
        return ReferenceResource::collection(MasterRequests::list(GenericCondition::query(), $request, ['sort_order' => 'sort_order', 'name' => 'name'], 'sort_order'));
    }

    public function transactionTypes(Request $request): AnonymousResourceCollection
    {
        return ReferenceResource::collection(MasterRequests::list(GenericTransactionType::query(), $request, ['sort_order' => 'sort_order', 'name' => 'name'], 'sort_order'));
    }
}
