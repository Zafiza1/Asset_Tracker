<?php

namespace App\Domain\Dashboards\Models;

use App\Domain\Shared\Database\JsonObject;
use App\Domain\Shared\Tenancy\BelongsToOrganizationOrSystem;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A widget of a template; same owner as its template (a database trigger checks it).
 *
 * @property string $id
 * @property string|null $organization_id
 * @property string $template_id
 * @property string $widget_catalog_id
 * @property string|null $title
 * @property array<string, mixed> $configuration
 * @property array{w?: int, h?: int} $layout
 * @property int $sort_order
 */
class TemplateWidget extends Model
{
    use BelongsToOrganizationOrSystem, HasUuids;

    protected $fillable = ['widget_catalog_id', 'title', 'configuration', 'layout', 'sort_order'];

    protected function casts(): array
    {
        return [
            'configuration' => JsonObject::class,
            'layout' => JsonObject::class,
            'sort_order' => 'integer',
        ];
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(DashboardTemplate::class, 'template_id');
    }

    public function catalog(): BelongsTo
    {
        return $this->belongsTo(WidgetCatalog::class, 'widget_catalog_id');
    }
}
