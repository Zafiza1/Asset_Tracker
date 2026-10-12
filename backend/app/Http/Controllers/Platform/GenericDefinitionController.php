<?php

namespace App\Http\Controllers\Platform;

use App\Domain\GenericMaster\Models\GenericAssetDefinition;
use App\Domain\GenericMaster\Services\GenericCatalogService;
use App\Domain\Shared\Masters\MasterStatus;
use App\Domain\Shared\Masters\MasterWriter;
use App\Http\Controllers\Controller;
use App\Http\Resources\GenericDefinitionResource;
use App\Http\Resources\GenericVersionResource;
use App\Http\Support\MasterRequests;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

class GenericDefinitionController extends Controller
{
    public function __construct(private readonly GenericCatalogService $catalog) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $request->validate(['category_id' => ['nullable', 'uuid']]);
        $query = GenericAssetDefinition::query()->with(['category', 'currentVersion'])
            ->when($request->query('category_id'), fn ($q, $id) => $q->where('category_id', $id));

        return GenericDefinitionResource::collection(MasterRequests::list($query, $request,
            ['code' => 'code', 'name' => 'name', 'status' => 'status', 'created_at' => 'created_at'], 'name'));
    }

    /** Creates the definition with an empty draft v1. */
    public function store(Request $request): GenericDefinitionResource
    {
        MasterRequests::normalizeCode($request);
        $data = $request->validate([
            'code' => [...MasterRequests::codeRules(40), Rule::unique('generic_asset_definitions', 'code')],
            'name' => ['required', 'string', 'max:150'],
            'description' => ['nullable', 'string', 'max:1000'],
            'category_id' => ['required', 'uuid'],
        ]);

        return new GenericDefinitionResource($this->load($this->catalog->createDefinition($data)));
    }

    public function show(GenericAssetDefinition $genericDefinition): GenericDefinitionResource
    {
        return new GenericDefinitionResource($this->load($genericDefinition));
    }

    public function update(Request $request, GenericAssetDefinition $genericDefinition): GenericDefinitionResource
    {
        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:150'],
            'description' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'category_id' => ['sometimes', 'required', 'uuid'],
            'status' => ['sometimes', Rule::in(MasterStatus::EDITABLE)],
        ]);

        return new GenericDefinitionResource($this->load($this->catalog->updateDefinition($genericDefinition, $data)));
    }

    public function archive(Request $request, GenericAssetDefinition $genericDefinition): GenericDefinitionResource
    {
        $data = $request->validate(MasterRequests::archiveRules());

        return new GenericDefinitionResource($this->load($this->catalog->archiveDefinition($genericDefinition, $data['reason'] ?? null)));
    }

    public function restore(GenericAssetDefinition $genericDefinition, MasterWriter $writer): GenericDefinitionResource
    {
        return new GenericDefinitionResource($this->load($writer->restore($genericDefinition, 'generic_definition.restored')));
    }

    /** New draft, starting from the latest version's attributes. */
    public function createVersion(GenericAssetDefinition $genericDefinition): GenericVersionResource
    {
        return new GenericVersionResource($this->catalog->createDraft($genericDefinition)->load(['definition', 'attributeDefinitions.unit']));
    }

    private function load(GenericAssetDefinition $definition): GenericAssetDefinition
    {
        return $definition->load([
            'category', 'currentVersion',
            'versions' => fn ($q) => $q->withCount('attributeDefinitions')->orderByDesc('version_number'),
        ]);
    }
}
