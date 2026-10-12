<?php

namespace App\Domain\GenericMaster\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 * @property string $definition_version_id
 * @property string $key
 * @property string $label
 * @property string|null $help_text
 * @property string $data_type
 * @property bool $is_required
 * @property array<string, mixed> $validation
 * @property list<array{value: string, label: string}>|null $options
 * @property string|null $unit_id
 * @property mixed $default_value
 * @property bool $is_searchable
 * @property bool $is_controlled
 * @property list<string> $overridable
 * @property int $sort_order
 */
class GenericAttributeDefinition extends Model
{
    use HasUuids;

    /** Fields copied when a new draft version is created from an earlier one. */
    public const COPYABLE = ['key', 'label', 'help_text', 'data_type', 'is_required', 'validation', 'options',
        'unit_id', 'default_value', 'is_searchable', 'is_controlled', 'overridable', 'sort_order'];

    protected $fillable = self::COPYABLE;

    protected function casts(): array
    {
        return [
            'is_required' => 'boolean',
            'is_searchable' => 'boolean',
            'is_controlled' => 'boolean',
            'validation' => 'array',
            'options' => 'array',
            'default_value' => 'json',
            'overridable' => 'array',
            'sort_order' => 'integer',
        ];
    }

    public function version(): BelongsTo
    {
        return $this->belongsTo(GenericDefinitionVersion::class, 'definition_version_id');
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    /**
     * Shape used inside effective schemas (see SchemaResolver).
     *
     * @return array<string, mixed>
     */
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
            'controlled' => $this->is_controlled,
            'overridable' => $this->overridable ?? [],
            'sort_order' => $this->sort_order,
        ];
    }
}
