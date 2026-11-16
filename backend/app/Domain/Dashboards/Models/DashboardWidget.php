<?php

namespace App\Domain\Dashboards\Models;

use App\Domain\Shared\Database\JsonObject;
use App\Domain\Shared\Tenancy\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A widget on a dashboard: a catalog widget with its own title, configuration and layout.
 * Only reachable together with its dashboard (composite FK and RLS on the parent).
 *
 * @property string $id
 * @property string $organization_id
 * @property string $dashboard_id
 * @property string $widget_catalog_id
 * @property string|null $title
 * @property array<string, mixed> $configuration
 * @property array{w?: int, h?: int} $layout
 * @property int $sort_order
 * @property bool $is_visible
 */
class DashboardWidget extends Model
{
    use BelongsToOrganization, HasUuids;

    protected $fillable = ['widget_catalog_id', 'title', 'configuration', 'layout', 'sort_order', 'is_visible'];

    protected function casts(): array
    {
        return [
            'configuration' => JsonObject::class,
            'layout' => JsonObject::class,
            'sort_order' => 'integer',
            'is_visible' => 'boolean',
        ];
    }

    public function dashboard(): BelongsTo
    {
        return $this->belongsTo(Dashboard::class);
    }

    public function catalog(): BelongsTo
    {
        return $this->belongsTo(WidgetCatalog::class, 'widget_catalog_id');
    }
}
