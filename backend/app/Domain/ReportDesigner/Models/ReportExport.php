<?php

namespace App\Domain\ReportDesigner\Models;

use App\Domain\Identity\Models\User;
use App\Domain\Shared\Database\JsonObject;
use App\Domain\Shared\Tenancy\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One report generation: pins the template version it rendered, the format, the applied
 * filters (as a snapshot), and the resulting file. History rows are kept for audit and
 * re-download; `expires_at` marks when the file may be purged.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $template_id
 * @property string $template_version_id
 * @property string $export_number
 * @property string $format 'pdf' | 'xlsx'
 * @property string $status ExportStatus code
 * @property array<string, mixed> $filters
 * @property int|null $row_count
 * @property int|null $file_size
 * @property string|null $error
 * @property string|null $created_by
 * @property Carbon|null $completed_at
 * @property Carbon|null $expires_at
 */
class ReportExport extends Model
{
    use BelongsToOrganization, HasUuids;

    public const FORMAT_PDF = 'pdf';

    public const FORMAT_XLSX = 'xlsx';

    public const FORMATS = [self::FORMAT_PDF, self::FORMAT_XLSX];

    protected $fillable = [
        'template_id', 'template_version_id', 'export_number', 'format', 'status', 'filters',
        'row_count', 'file_size', 'error', 'created_by', 'completed_at', 'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'filters' => JsonObject::class,
            'row_count' => 'integer',
            'file_size' => 'integer',
            'completed_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(ReportTemplate::class, 'template_id');
    }

    public function version(): BelongsTo
    {
        return $this->belongsTo(ReportTemplateVersion::class, 'template_version_id');
    }

    public function files(): HasMany
    {
        return $this->hasMany(ReportExportFile::class, 'export_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
