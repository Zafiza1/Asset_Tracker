<?php

namespace App\Domain\GenericMaster\Attributes;

/**
 * Turns a validated create/update request for an attribute definition into the columns
 * to persist. Partial updates are merged onto the existing definition first so the
 * semantic checks always see the complete, final definition.
 */
final class AttributePayload
{
    public function __construct(private readonly AttributeDefinitionRules $rules) {}

    /**
     * @param  array<string, mixed>  $input  validated request data
     * @param  array<string, mixed>|null  $existing  current column values (update) or null (create)
     * @param  list<string>  $columns  persisted columns of the target model
     * @return array<string, mixed>
     */
    public function prepare(array $input, ?array $existing, array $columns, bool $withOverridable): array
    {
        $merged = [...($existing ?? []), ...$input];
        $normalized = $this->rules->normalize([
            'data_type' => $merged['data_type'],
            'validation' => $merged['validation'] ?? [],
            'options' => $merged['options'] ?? null,
            'default_value' => $merged['default_value'] ?? null,
            'overridable' => $merged['overridable'] ?? [],
        ], $withOverridable);

        $result = [
            ...$merged,
            'validation' => $normalized['validation'],
            'options' => $normalized['options'],
            'default_value' => $normalized['default_value'],
        ];
        if ($withOverridable) {
            $result['overridable'] = $normalized['overridable'];
        }

        return array_intersect_key($result, array_flip($columns));
    }
}
