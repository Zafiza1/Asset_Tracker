<?php

namespace App\Http\Controllers\Tenant;

use App\Domain\TenantMaster\Models\TenantAttributeDefinition;
use App\Domain\TenantMaster\Models\TenantDefinitionVersion;
use App\Domain\TenantMaster\Services\TenantDefinitionService;
use App\Http\Controllers\Controller;
use App\Http\Resources\AttributeDefinitionResource;
use App\Http\Resources\TenantVersionResource;
use App\Http\Support\MasterRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/** Versions of tenant definitions: draft editing, tenant attributes, overrides, release. */
class TenantVersionController extends Controller
{
    private const RELATIONS = ['definition', 'genericVersion', 'tenantAttributes.unit', 'overrides'];

    public function __construct(private readonly TenantDefinitionService $definitions) {}

    public function show(TenantDefinitionVersion $tenantVersion): TenantVersionResource
    {
        return (new TenantVersionResource($tenantVersion->load(self::RELATIONS)))->withSchema();
    }

    public function update(Request $request, TenantDefinitionVersion $tenantVersion): TenantVersionResource
    {
        $data = $request->validate([
            'changelog' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'generic_definition_version_id' => ['sometimes', 'required', 'uuid'],
        ]);

        return new TenantVersionResource($this->definitions->updateDraft($tenantVersion, $data)->load(self::RELATIONS));
    }

    public function destroy(TenantDefinitionVersion $tenantVersion): Response
    {
        $this->definitions->discardDraft($tenantVersion);

        return response()->noContent();
    }

    /**
     * The frozen schema of a released version, or a live preview of a draft together with
     * the issues that would block its release.
     */
    public function effectiveSchema(TenantDefinitionVersion $tenantVersion): JsonResponse
    {
        if (! $tenantVersion->isDraft()) {
            return response()->json(['data' => $tenantVersion->effective_schema, 'meta' => [
                'schema_hash' => $tenantVersion->schema_hash, 'issues' => [], 'frozen' => true,
            ]]);
        }
        $resolved = $this->definitions->preview($tenantVersion);

        return response()->json(['data' => $resolved->schema, 'meta' => [
            'schema_hash' => $resolved->hash, 'issues' => $resolved->issues, 'frozen' => false,
        ]]);
    }

    public function release(TenantDefinitionVersion $tenantVersion): TenantVersionResource
    {
        return (new TenantVersionResource($this->definitions->release($tenantVersion)->load(self::RELATIONS)))->withSchema();
    }

    public function deprecate(TenantDefinitionVersion $tenantVersion): TenantVersionResource
    {
        return new TenantVersionResource($this->definitions->deprecate($tenantVersion)->load(self::RELATIONS));
    }

    public function storeAttribute(Request $request, TenantDefinitionVersion $tenantVersion): AttributeDefinitionResource
    {
        $data = $request->validate(MasterRequests::attributeRules(creating: true, generic: false));

        return new AttributeDefinitionResource($this->definitions->addAttribute($tenantVersion, $data)->load('unit'));
    }

    public function updateAttribute(Request $request, TenantAttributeDefinition $tenantAttribute): AttributeDefinitionResource
    {
        $data = $request->validate(MasterRequests::attributeRules(creating: false, generic: false));

        return new AttributeDefinitionResource($this->definitions->updateAttribute($tenantAttribute, $data)->load('unit'));
    }

    public function destroyAttribute(TenantAttributeDefinition $tenantAttribute): Response
    {
        $this->definitions->deleteAttribute($tenantAttribute);

        return response()->noContent();
    }

    /** Replaces all overrides of the draft. */
    public function syncOverrides(Request $request, TenantDefinitionVersion $tenantVersion): TenantVersionResource
    {
        $data = $request->validate([
            'overrides' => ['present', 'array', 'max:200'],
            'overrides.*.generic_attribute_key' => ['required', 'string', 'max:50'],
            'overrides.*.overrides' => ['present', 'array'],
        ]);

        return new TenantVersionResource($this->definitions->syncOverrides($tenantVersion, $data['overrides'])->load(self::RELATIONS));
    }
}
