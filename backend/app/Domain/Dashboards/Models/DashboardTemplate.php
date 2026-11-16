<?php

namespace App\Domain\Dashboards\Models;

use App\Domain\Identity\Models\User;
use App\Domain\Shared\Tenancy\BelongsToOrganizationOrSystem;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A dashboard template: a system one (organization_id NULL, from SystemTemplates, read-only
 * for organizations) or an organization's own, possibly copied from a system template.
 * Dashboards copy a template's widgets; a newer version never rewrites them by itself.
 *
 * @property string $id
 * @property string|null $organization_id
 * @property string|null $source_template_id
 * @property string $template_key
 * @property string $name
 * @property string|null $description
 * @property string $dashboard_type
 * @property string|null $category_type_key
 * @property string $scope
 * @property int $version
 * @property bool $is_default
 * @property bool $is_active
 * @property string|null $created_by
 */
class DashboardTemplate extends Model
{
    use BelongsToOrganizationOrSystem, HasUuids;

    public const SCOPE_SYSTEM = 'system';

    public const SCOPE_ORGANIZATION = 'organization';

    protected $fillable = ['template_key', 'name', 'description', 'dashboard_type', 'category_type_key', 'is_default', 'is_active'];

    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'is_default' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function widgets(): HasMany
    {
        return $this->hasMany(TemplateWidget::class, 'template_id')->orderBy('sort_order');
    }

    public function source(): BelongsTo
    {
        return $this->belongsTo(self::class, 'source_template_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
