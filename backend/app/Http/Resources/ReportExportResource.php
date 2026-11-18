<?php

namespace App\Http\Resources;

use App\Domain\ReportDesigner\ExportStatus;
use App\Domain\ReportDesigner\Models\ReportExport;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin ReportExport */
class ReportExportResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        // Past expiry counts as expired before the scheduled clean-up marks it.
        $expired = $this->status === ExportStatus::EXPIRED || ($this->expires_at?->isPast() ?? false);

        return [
            'id' => $this->id,
            'export_number' => $this->export_number,
            'format' => $this->format,
            'status' => $expired ? ExportStatus::EXPIRED : $this->status,
            'downloadable' => $this->status === ExportStatus::COMPLETED && ! $expired,
            'filters' => $this->filters['values'] ?? [],
            'filter_description' => $this->filters['description'] ?? [],
            'row_count' => $this->row_count,
            'file_size' => $this->file_size,
            'template' => $this->whenLoaded('template', fn () => ['id' => $this->template->id, 'code' => $this->template->code, 'name' => $this->template->name]),
            'version_number' => $this->whenLoaded('version', fn () => $this->version->version_number),
            'created_by' => $this->whenLoaded('creator', fn () => $this->creator?->name),
            'created_at' => $this->created_at?->toIso8601String(),
            'expires_at' => $this->expires_at?->toIso8601String(),
        ];
    }
}
