<?php

namespace App\Domain\TenantMaster\Models;

use App\Domain\GenericMaster\Models\Unit;
use App\Domain\Shared\Tenancy\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Additional attribute defined by an organization on top of the generic attributes.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $tenant_definition_version_id
 * @property string $key
 * @property string $label
 * @property string $data_type
 * @property bool $is_required
 * @property array<string, mixed> $validation
 * @property list<array{value: string, label: string}>|null $options
 * @property mixed $default_value
 * @property bool $is_searchable
 * @property int $sort_order
 */
class TenantAttributeDefinition extends Model
{
    use BelongsToOrganization, HasUuids;

    public const COPYABLE = ['key', 'label', 'help_text', 'data_type', 'is_required', 'validation', 'options',
        'unit_id', 'default_value', 'is_searchable', 'sort_order'];

    protected $fillable = self::COPYABLE;

    protected function casts(): array
    {
        return [
            'is_required' => 'boolean',
            'is_searchable' => 'boolean',
            'validation' => 'array',
            'options' => 'array',
            'default_value' => 'json',
            'sort_order' => 'integer',
        ];
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    /** @return array<string, mixed> */
    public function toSpec(): array
    {
        return [
            'key' => $this->key,
            'label' => $this->label,
            'help_text' => $this->help_text,
            'data_type' => $this->data_type,
            'required' => $this->is_required,
            'validation' => $this->validation ?? [],
            'options' => $this->options,
            'unit' => $this->unit?->toSpec(),
            'default_value' => $this->default_value,
            'searchable' => $this->is_searchable,
            'controlled' => false,
            'sort_order' => $this->sort_order,
        ];
    }
}
