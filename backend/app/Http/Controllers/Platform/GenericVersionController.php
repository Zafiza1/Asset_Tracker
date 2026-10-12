<?php

namespace App\Http\Controllers\Platform;

use App\Domain\GenericMaster\Models\GenericAttributeDefinition;
use App\Domain\GenericMaster\Models\GenericDefinitionVersion;
use App\Domain\GenericMaster\Services\GenericCatalogService;
use App\Http\Controllers\Controller;
use App\Http\Resources\AttributeDefinitionResource;
use App\Http\Resources\GenericVersionResource;
use App\Http\Support\MasterRequests;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/** Generic definition versions: draft editing and attributes (manage), release/deprecate (release). */
class GenericVersionController extends Controller
{
    private const RELATIONS = ['definition', 'attributeDefinitions.unit'];

    public function __construct(private readonly GenericCatalogService $catalog) {}

    public function show(GenericDefinitionVersion $genericVersion): GenericVersionResource
    {
        return new GenericVersionResource($genericVersion->load(self::RELATIONS));
    }

    public function update(Request $request, GenericDefinitionVersion $genericVersion): GenericVersionResource
    {
        $data = $request->validate([
            'changelog' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'migration_notes' => ['sometimes', 'nullable', 'string', 'max:5000'],
        ]);

        return new GenericVersionResource($this->catalog->updateDraft($genericVersion, $data)->load(self::RELATIONS));
    }

    public function destroy(GenericDefinitionVersion $genericVersion): Response
    {
        $this->catalog->discardDraft($genericVersion);

        return response()->noContent();
    }

    public function release(GenericDefinitionVersion $genericVersion): GenericVersionResource
    {
        return new GenericVersionResource($this->catalog->release($genericVersion)->load(self::RELATIONS));
    }

    public function deprecate(GenericDefinitionVersion $genericVersion): GenericVersionResource
    {
        return new GenericVersionResource($this->catalog->deprecate($genericVersion)->load(self::RELATIONS));
    }

    public function storeAttribute(Request $request, GenericDefinitionVersion $genericVersion): AttributeDefinitionResource
    {
        $data = $request->validate(MasterRequests::attributeRules(creating: true, generic: true));

        return new AttributeDefinitionResource($this->catalog->addAttribute($genericVersion, $data)->load('unit'));
    }

    public function updateAttribute(Request $request, GenericAttributeDefinition $genericAttribute): AttributeDefinitionResource
    {
        $data = $request->validate(MasterRequests::attributeRules(creating: false, generic: true));

        return new AttributeDefinitionResource($this->catalog->updateAttribute($genericAttribute, $data)->load('unit'));
    }

    public function destroyAttribute(GenericAttributeDefinition $genericAttribute): Response
    {
        $this->catalog->deleteAttribute($genericAttribute);

        return response()->noContent();
    }
}
