<?php

namespace App\Domain\ReportDesigner\Models;

use App\Domain\Identity\Models\User;
use App\Domain\ReportDesigner\TemplateStatus;
use App\Domain\Shared\Database\JsonObject;
use App\Domain\Shared\Tenancy\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One immutable-once-published snapshot of a template's designer document and Excel
 * configuration. Draft versions are editable; publishing freezes the row (DB trigger) and
 * points the template's active_version at it. Rows are never deleted.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $template_id
 * @property int $version_number
 * @property string $status 'draft' | 'published' (TemplateStatus codes)
 * @property array<string, mixed> $design
 * @property array<string, mixed> $excel_config
 * @property string|null $change_note
 * @property string|null $created_by
 * @property Carbon|null $published_at
 * @property string|null $published_by
 */
class ReportTemplateVersion extends Model
{
    use BelongsToOrganization, HasUuids;

    protected $fillable = [
        'template_id', 'version_number', 'status', 'design', 'excel_config', 'change_note',
        'created_by', 'published_at', 'published_by',
    ];

    protected function casts(): array
    {
        return [
            'version_number' => 'integer',
            'design' => JsonObject::class,
            'excel_config' => JsonObject::class,
            'published_at' => 'datetime',
        ];
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(ReportTemplate::class, 'template_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function publisher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'published_by');
    }

    public function isPublished(): bool
    {
        return $this->status === TemplateStatus::PUBLISHED;
    }
}
