<?php

namespace App\Domain\ReportDesigner\Models;

use App\Domain\Shared\Tenancy\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The generated file of a report export, on private storage. Download goes through an
 * authorized endpoint (signed URL or permission re-check), never a public path.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $export_id
 * @property string $disk
 * @property string $path
 * @property string $original_name
 * @property string $mime
 * @property int $size
 */
class ReportExportFile extends Model
{
    use BelongsToOrganization, HasUuids;

    protected $fillable = ['export_id', 'disk', 'path', 'original_name', 'mime', 'size'];

    protected function casts(): array
    {
        return ['size' => 'integer'];
    }

    public function export(): BelongsTo
    {
        return $this->belongsTo(ReportExport::class, 'export_id');
    }
}
