<?php

namespace App\Http\Resources;

use App\Domain\Approvals\ApprovalActions;
use App\Domain\Approvals\ApprovalDetails;
use App\Domain\Approvals\Models\ApprovalRequest;
use App\Domain\Audit\Models\AuditLog;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ApprovalRequest
 *
 * `details` (readable submitted input), `impact`, `asset_id` and `history` are only built for
 * single requests, not for lists.
 */
class ApprovalRequestResource extends JsonResource
{
    public bool $withDetails = false;

    private const HISTORY_LABELS = [
        'approval.submitted' => 'Diajukan',
        'approval.approved' => 'Disetujui',
        'approval.rejected' => 'Ditolak',
        'approval.cancelled' => 'Dibatalkan',
    ];

    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'category' => $this->category,
            'category_label' => ApprovalActions::CATEGORIES[$this->category] ?? $this->category,
            'action' => $this->action,
            'action_label' => ApprovalActions::label($this->action),
            'summary' => $this->summary,
            'status' => $this->status,
            'requester' => $this->whenLoaded('requester', fn () => $this->requester ? ['id' => $this->requester->id, 'name' => $this->requester->name] : null),
            'decider' => $this->whenLoaded('decider', fn () => $this->decider ? ['id' => $this->decider->id, 'name' => $this->decider->name] : null),
            'decided_at' => $this->decided_at?->toIso8601String(),
            'decision_note' => $this->decision_note,
            'details' => $this->when($this->withDetails, fn () => ApprovalDetails::rows($this->resource)),
            'impact' => $this->when($this->withDetails, fn () => ApprovalActions::impact($this->action)),
            'asset_id' => $this->when($this->withDetails, fn () => $this->assetId()),
            'history' => $this->when($this->withDetails, fn () => $this->history()),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }

    public function withDetails(): static
    {
        $this->withDetails = true;

        return $this;
    }

    /** The existing asset the request is about (for a link to its detail page). */
    private function assetId(): ?string
    {
        $id = preg_match('#^api/assets/([0-9a-f-]{36})(/|$)#i', $this->path, $m) ? $m[1] : null;

        return $id ?? (is_string($this->payload['asset_id'] ?? null) ? $this->payload['asset_id'] : null);
    }

    /**
     * Who submitted and who decided, oldest first, from the audit trail.
     *
     * @return list<array{action: string, label: string, actor: string|null, note: string|null, at: string|null}>
     */
    private function history(): array
    {
        return AuditLog::query()->with('actor:id,name')
            ->forOrganization($this->organization_id)
            ->where('entity_type', class_basename(ApprovalRequest::class))
            ->where('entity_id', $this->id)
            ->whereIn('action', array_keys(self::HISTORY_LABELS))
            ->orderBy('created_at')
            ->get()
            ->map(fn (AuditLog $log) => [
                'action' => $log->action,
                'label' => self::HISTORY_LABELS[$log->action],
                'actor' => $log->actor?->name,
                'note' => $log->after['decision_note'] ?? null,
                'at' => $log->created_at?->toIso8601String(),
            ])
            ->values()
            ->all();
    }
}
