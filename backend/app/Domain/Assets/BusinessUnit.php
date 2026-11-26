<?php

namespace App\Domain\Assets;

use App\Domain\Shared\Tenancy\Tenancy;
use App\Domain\TenantMaster\Models\TenantCategoryAttribute;
use App\Domain\TenantMaster\Services\CategoryAttributeService;

/**
 * The Business Unit field of a cylinder (PT SIG / PT CDO) follows the organization the asset is
 * registered in, so it is never chosen by hand: the option whose label is the organization's name
 * is stored whatever was sent. A category without such a field, or an organization whose name is
 * not one of its options, keeps the field as an ordinary one.
 */
final class BusinessUnit
{
    public const LABEL = 'Business Unit';

    public function __construct(
        private readonly Tenancy $tenancy,
        private readonly CategoryAttributeService $attributes,
    ) {}

    /**
     * The submitted values with the Business Unit set from the organization; unchanged when the
     * category has no Business Unit field the organization's name decides.
     *
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    public function apply(string $categoryId, array $values): array
    {
        $fixed = $this->fixed($categoryId);

        return $fixed === null ? $values : [...$values, $fixed['key'] => $fixed['value']];
    }

    /**
     * Key and value of the category's Business Unit field as the organization decides it.
     *
     * @return array{key: string, value: string}|null
     */
    public function fixed(string $categoryId): ?array
    {
        $field = $this->attributes->effective($categoryId)->first(fn (TenantCategoryAttribute $a) => $a->data_type === 'enum' && mb_strtolower(trim($a->label)) === mb_strtolower(self::LABEL));
        if ($field === null) {
            return null;
        }
        $organization = mb_strtolower(trim((string) $this->tenancy->tenant()->organization->name));
        foreach ($field->options ?? [] as $option) {
            if (mb_strtolower(trim((string) ($option['label'] ?? ''))) === $organization) {
                return ['key' => $field->key, 'value' => $option['value']];
            }
        }

        return null;
    }
}
