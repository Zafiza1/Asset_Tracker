<?php

namespace App\Domain\ReportDesigner\Models;

use App\Domain\Identity\Models\User;
use App\Domain\Shared\Tenancy\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An image or signature picture uploaded for a report template. `version_id` is set once a
 * published version references the asset; files live on the local disk and are served to
 * authorized members only.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $template_id
 * @property string|null $version_id
 * @property string $kind 'image' | 'signature'
 * @property string $disk
 * @property string $path
 * @property string $original_name
 * @property string $mime
 * @property int $size
 * @property string|null $uploaded_by
 */
class ReportTemplateAsset extends Model
{
    use BelongsToOrganization, HasUuids;

    public const KIND_IMAGE = 'image';

    public const KIND_SIGNATURE = 'signature';

    public const KINDS = [self::KIND_IMAGE, self::KIND_SIGNATURE];

    /** MIME types accepted for template images (PNG/JPG signature and picture uploads). */
    public const ALLOWED_MIMES = ['image/png', 'image/jpeg'];

    public const MAX_SIZE_KB = 2048;

    protected $fillable = [
        'template_id', 'version_id', 'kind', 'disk', 'path', 'original_name', 'mime', 'size', 'uploaded_by',
    ];

    protected function casts(): array
    {
        return ['size' => 'integer'];
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(ReportTemplate::class, 'template_id');
    }

    public function version(): BelongsTo
    {
        return $this->belongsTo(ReportTemplateVersion::class, 'version_id');
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
