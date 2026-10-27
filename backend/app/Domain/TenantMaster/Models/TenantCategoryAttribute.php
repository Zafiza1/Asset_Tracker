<?php

namespace App\Domain\TenantMaster\Models;

use App\Domain\Shared\Tenancy\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Custom field of an asset category, e.g. "Nomor Polisi" on "Kendaraan". Applies to assets of
 * the category and of all its subcategories. Key, data type and category never change
 * (DB trigger); removing a field archives it so its key is never reused.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $category_id
 * @property string $key
 * @property string $label
 * @property string $data_type
 * @property bool $is_required
 * @property list<array{value: string, label: string}>|null $options
 * @property int $sort_order
 * @property string $status
 * @property bool $is_default
 * @property string|null $section
 */
class TenantCategoryAttribute extends Model
{
    use BelongsToOrganization, HasUuids;

    protected $fillable = ['label', 'is_required', 'options', 'is_default', 'section'];

    protected function casts(): array
    {
        return [
            'is_required' => 'boolean',
            'is_default' => 'boolean',
            'options' => 'array',
            'sort_order' => 'integer',
        ];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(TenantAssetCategory::class, 'category_id');
    }

    /** Spec understood by AttributeValueValidator. @return array<string, mixed> */
    public function toSpec(): array
    {
        return [
            'key' => $this->key,
            'label' => $this->label,
            'data_type' => $this->data_type,
            'required' => $this->is_required,
            'validation' => [],
            'options' => $this->options,
        ];
    }
}
