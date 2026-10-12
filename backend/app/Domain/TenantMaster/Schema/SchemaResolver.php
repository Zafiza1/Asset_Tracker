<?php

namespace App\Domain\TenantMaster\Schema;

use App\Domain\GenericMaster\Attributes\AttributeType;
use App\Domain\GenericMaster\Attributes\OverridePolicy;
use App\Domain\GenericMaster\Models\GenericAttributeDefinition;
use App\Domain\GenericMaster\Models\GenericDefinitionVersion;
use App\Domain\TenantMaster\Models\TenantDefinitionVersion;

/**
 * Computes the effective schema of a tenant definition version:
 *
 *   generic attributes of the pinned generic version
 *   + tenant overrides (only allowed, only narrowing — see OverridePolicy)
 *   + tenant attributes (keys may not collide with generic or core asset keys)
 *
 * The result is deterministic: the same inputs give the same canonical JSON and hash.
 * It never writes anything; releasing a version freezes its output.
 */
final class SchemaResolver
{
    public const SCHEMA_VERSION = 1;

    public function __construct(private readonly OverridePolicy $overrides) {}

    /** @return list<array<string, mixed>> generic attribute specs, including `overridable` */
    public function genericAttributes(GenericDefinitionVersion $version): array
    {
        return $version->attributeDefinitions()->with('unit')->get()
            ->map(fn (GenericAttributeDefinition $a) => $a->toSpec())->values()->all();
    }

    /** Fingerprint of a generic version's structure, stored when it is released. */
    public function genericHash(GenericDefinitionVersion $version): string
    {
        return CanonicalJson::hash([
            'definition_code' => $version->definition->code,
            'version_number' => $version->version_number,
            'attributes' => $this->genericAttributes($version),
        ]);
    }

    public function resolve(TenantDefinitionVersion $version): ResolvedSchema
    {
        $version->loadMissing(['definition', 'genericVersion.definition']);
        $generic = $version->genericVersion;
        $issues = [];

        if ($generic->status !== GenericDefinitionVersion::RELEASED) {
            $issues[] = "Versi Generic Master v{$generic->version_number} sudah tidak berlaku ({$generic->status}); gunakan versi rilis terbaru.";
        }

        $overrides = $version->overrides()->get()->keyBy('generic_attribute_key');
        $attributes = [];
        $genericKeys = [];

        foreach ($this->genericAttributes($generic) as $base) {
            $genericKeys[] = $base['key'];
            $spec = $base;
            $applied = [];
            if ($override = $overrides->get($base['key'])) {
                [$spec, $attributeIssues, $applied] = $this->overrides->apply($base, $override->overrides);
                foreach ($attributeIssues as $issue) {
                    $issues[] = "{$base['label']} ({$base['key']}): {$issue}";
                }
            }
            unset($spec['overridable']);
            $attributes[] = [...$spec, 'source' => 'generic', 'overridden' => $applied];
        }

        foreach ($overrides->keys()->diff($genericKeys) as $orphan) {
            $issues[] = "Override untuk atribut '{$orphan}' tidak ada pada versi Generic Master yang dipakai.";
        }

        foreach ($version->tenantAttributes()->with('unit')->get() as $attribute) {
            $spec = $attribute->toSpec();
            if (in_array($spec['key'], $genericKeys, true)) {
                $issues[] = "Kunci atribut '{$spec['key']}' sudah dipakai oleh Generic Master.";
            } elseif (in_array($spec['key'], AttributeType::RESERVED_KEYS, true)) {
                $issues[] = "Kunci atribut '{$spec['key']}' dicadangkan untuk data inti aset.";
            }
            $attributes[] = [...$spec, 'source' => 'tenant', 'overridden' => []];
        }

        $schema = [
            'schema_version' => self::SCHEMA_VERSION,
            'generic' => [
                'definition_id' => $generic->definition_id,
                'definition_code' => $generic->definition->code,
                'version_id' => $generic->id,
                'version_number' => $generic->version_number,
            ],
            'tenant' => [
                'definition_id' => $version->tenant_definition_id,
                'definition_code' => $version->definition->code,
                'version_id' => $version->id,
                'version_number' => $version->version_number,
            ],
            'attributes' => $attributes,
        ];

        return new ResolvedSchema($schema, $issues, CanonicalJson::hash($schema));
    }
}
