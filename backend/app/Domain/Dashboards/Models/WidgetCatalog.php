<?php

namespace App\Domain\Dashboards\Models;

use App\Domain\Shared\Database\JsonObject;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * A widget the system offers (reference data, synced from SystemWidgets; read-only for the
 * runtime role). Users only pick catalog widgets and set their configuration, which is
 * validated against configuration_schema: no code, SQL or markup ever comes from a user.
 *
 * @property string $id
 * @property string $widget_key
 * @property string $name
 * @property string|null $description
 * @property string $component_type
 * @property string|null $required_permission
 * @property array<string, mixed> $configuration_schema
 * @property list<string> $supported_data_sources
 * @property array{w?: int, h?: int} $default_layout
 * @property int $sort_order
 * @property bool $is_active
 */
class WidgetCatalog extends Model
{
    use HasUuids;

    protected $table = 'widget_catalog';

    protected function casts(): array
    {
        return [
            'configuration_schema' => JsonObject::class,
            'supported_data_sources' => 'array',
            'default_layout' => JsonObject::class,
            'is_active' => 'boolean',
        ];
    }
}
