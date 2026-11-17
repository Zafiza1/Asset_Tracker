<?php

namespace App\Domain\Dashboards\Models;

use App\Domain\Dashboards\DashboardType;
use App\Domain\Identity\Models\User;
use App\Domain\Shared\Tenancy\BelongsToOrganization;
use App\Domain\TenantMaster\Models\TenantAssetCategory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A dashboard: the container its widgets live in. Overview and category dashboards are
 * organization dashboards the system creates; custom ones are personal or organization.
 * A personal dashboard is visible to its owner only (also enforced by RLS).
 *
 * @property string $id
 * @property string $organization_id
 * @property string|null $created_by
 * @property string|null $template_id
 * @property int|null $template_version
 * @property string|null $category_id
 * @property string $name
 * @property string|null $description
 * @property string $dashboard_type
 * @property string $visibility
 * @property string $status
 * @property bool $is_default
 */
class Dashboard extends Model
{
    use BelongsToOrganization, HasUuids, SoftDeletes;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_ARCHIVED = 'archived';

    protected $fillable = ['name', 'description', 'visibility', 'status'];

    protected function casts(): array
    {
        return [
            'template_version' => 'integer',
            'is_default' => 'boolean',
        ];
    }

    public function widgets(): HasMany
    {
        return $this->hasMany(DashboardWidget::class)->orderBy('sort_order');
    }

    public function shares(): HasMany
    {
        return $this->hasMany(DashboardShare::class);
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(DashboardTemplate::class, 'template_id');
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(TenantAssetCategory::class, 'category_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isOverview(): bool
    {
        return $this->dashboard_type === DashboardType::OVERVIEW;
    }

    public function isCategory(): bool
    {
        return $this->dashboard_type === DashboardType::CATEGORY;
    }

    public function isCustom(): bool
    {
        return $this->dashboard_type === DashboardType::CUSTOM;
    }

    /** Overview and category dashboards: created by the system, one per organization / category, never deleted by users. */
    public function isSystemManaged(): bool
    {
        return ! $this->isCustom();
    }

    /** The template it was copied from has a newer version (applying it is an explicit action). */
    public function templateUpdateAvailable(): bool
    {
        return $this->template !== null && $this->template_version !== null && $this->template->version > $this->template_version;
    }

    public function isPersonal(): bool
    {
        return $this->visibility === DashboardType::PERSONAL;
    }
}
