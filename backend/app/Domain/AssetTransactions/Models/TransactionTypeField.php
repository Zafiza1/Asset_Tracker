<?php

namespace App\Domain\AssetTransactions\Models;

use App\Domain\Shared\Tenancy\BelongsToOrganization;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A form field of an organization's transaction type. `key` and `data_type` never change after
 * creation (database trigger), so stored values keep their meaning; see TransactionFieldTypes.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $type_definition_id
 * @property string $key
 * @property string $label
 * @property string $data_type
 * @property array<string, mixed> $settings
 * @property bool $is_required
 * @property int $sort_order
 */
class TransactionTypeField extends Model
{
    use BelongsToOrganization, HasUuids;

    protected $fillable = ['key', 'label', 'data_type', 'settings', 'is_required', 'sort_order'];

    protected function casts(): array
    {
        return [
            'is_required' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    /** Stored as a JSON object even when empty (the column requires an object). */
    protected function settings(): Attribute
    {
        return Attribute::make(
            get: fn (?string $value) => $value === null ? [] : (json_decode($value, true) ?: []),
            set: fn (?array $value) => json_encode((object) ($value ?? [])),
        );
    }

    public function definition(): BelongsTo
    {
        return $this->belongsTo(TransactionTypeDefinition::class, 'type_definition_id');
    }
}
