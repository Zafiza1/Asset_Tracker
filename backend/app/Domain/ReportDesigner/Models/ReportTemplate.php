<?php

namespace App\Domain\ReportDesigner\Models;

use App\Domain\Identity\Models\User;
use App\Domain\ReportDesigner\TemplateStatus;
use App\Domain\Shared\Tenancy\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * An organization's custom report template. Owned by the organization, not the creator:
 * it survives the creator being deactivated. Soft-deleted; `code` is unique per
 * organization among live templates.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $code
 * @property string $name
 * @property string|null $description
 * @property string|null $category
 * @property string $data_source
 * @property string $status
 * @property int|null $active_version version_number used for new exports; NULL until first publish
 * @property string|null $created_by
 */
class ReportTemplate extends Model
{
    use BelongsToOrganization, HasUuids, SoftDeletes;

    protected $fillable = ['code', 'name', 'description', 'category', 'data_source', 'status', 'active_version'];

    protected function casts(): array
    {
        return [
            'active_version' => 'integer',
            'deleted_at' => 'datetime',
        ];
    }

    public function versions(): HasMany
    {
        return $this->hasMany(ReportTemplateVersion::class, 'template_id')->orderByDesc('version_number');
    }

    public function draftVersion(): HasMany
    {
        return $this->versions()->where('status', TemplateStatus::DRAFT);
    }

    public function activeVersion(): HasMany
    {
        return $this->versions()->where('version_number', $this->active_version ?? 0);
    }

    public function assets(): HasMany
    {
        return $this->hasMany(ReportTemplateAsset::class, 'template_id');
    }

    public function exports(): HasMany
    {
        return $this->hasMany(ReportExport::class, 'template_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** New exports may only use a published template with a published active version. */
    public function canGenerate(): bool
    {
        return $this->status === TemplateStatus::PUBLISHED && $this->active_version !== null;
    }
}
