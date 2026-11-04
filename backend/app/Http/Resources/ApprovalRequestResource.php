<?php

namespace App\Http\Resources;

use App\Domain\Approvals\ApprovalActions;
use App\Domain\Approvals\ApprovalDetails;
use App\Domain\Approvals\Models\ApprovalRequest;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ApprovalRequest
 *
 * `details` (readable submitted input) is only built for single requests, not for lists.
 */
class ApprovalRequestResource extends JsonResource
{
    public bool $withDetails = false;

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
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }

    public function withDetails(): static
    {
        $this->withDetails = true;

        return $this;
    }
}
