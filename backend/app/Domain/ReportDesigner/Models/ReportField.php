<?php

namespace App\Domain\ReportDesigner\Models;

use App\Domain\Shared\Database\JsonObject;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * One field of the report field registry: an officially offered binding for a data source.
 * Rows are system reference data (like widget_catalog), synced from FieldCatalog; tenants
 * only read them. Templates reference fields by (data_source, field_key), never by raw
 * column names, so the registry stays the single gate for what a template may bind.
 *
 * @property string $id
 * @property string $data_source
 * @property string $field_key
 * @property string $label
 * @property string $data_type
 * @property string $bind_path
 * @property string|null $relation
 * @property bool $is_repeatable
 * @property array<string, mixed> $formats
 * @property string|null $required_permission
 * @property int $sort_order
 * @property bool $is_active
 */
class ReportField extends Model
{
    use HasUuids;

    protected $table = 'report_field_registry';

    protected $fillable = [
        'data_source', 'field_key', 'label', 'data_type', 'bind_path', 'relation',
        'is_repeatable', 'formats', 'required_permission', 'sort_order', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_repeatable' => 'boolean',
            'formats' => JsonObject::class,
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }
}
