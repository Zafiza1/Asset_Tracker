<?php

namespace App\Http\Controllers\Tenant;

use App\Domain\Approvals\ApprovalActions;
use App\Domain\Approvals\Models\ApprovalRequest;
use App\Domain\Approvals\Services\ApprovalService;
use App\Domain\Shared\Exceptions\ApiException;
use App\Domain\Shared\Tenancy\Tenancy;
use App\Http\Controllers\Controller;
use App\Http\Resources\ApprovalRequestResource;
use App\Http\Support\ListQuery;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

/**
 * Notifications menu: approval requests. Super Admin and Supervisor (approval.manage) see every
 * request of the organization and decide on pending ones; other users see their own requests.
 */
class ApprovalController extends Controller
{
    private const RELATIONS = ['requester', 'decider'];

    public function __construct(
        private readonly ApprovalService $approvals,
        private readonly Tenancy $tenancy,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $request->validate([
            'status' => ['nullable', Rule::in(ApprovalRequest::STATUSES)],
            ...$this->filterRules(),
        ]);

        $query = $this->filtered($request)->with(self::RELATIONS)
            ->when($request->query('status'), fn ($q, $status) => $q->where('status', $status));
        $approval = new ApprovalRequest;
        ListQuery::sort($query, $request, [
            'action' => 'action', 'category' => 'category', 'status' => 'status',
            'requester' => ListQuery::related($approval, 'requester', 'name'),
            'decider' => ListQuery::related($approval, 'decider', 'name'),
            'created_at' => 'created_at', 'decided_at' => 'decided_at',
        ], '-created_at');

        return ApprovalRequestResource::collection($query->paginate(ListQuery::perPage($request))->withQueryString());
    }

    /** Number of requests per status (and in total) under the list filters, for the status tabs. */
    public function counts(Request $request): JsonResponse
    {
        $request->validate($this->filterRules());
        $byStatus = $this->filtered($request)->toBase()->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status');
        $counts = [];
        foreach (ApprovalRequest::STATUSES as $status) {
            $counts[$status] = (int) ($byStatus[$status] ?? 0);
        }
        $counts['all'] = array_sum($counts);

        return response()->json(['data' => $counts]);
    }

    /** Pending requests the user should look at: all of them for approvers, their own for others. */
    public function summary(): JsonResponse
    {
        return response()->json(['data' => [
            'pending' => $this->visible(false)->where('status', ApprovalRequest::PENDING)->count(),
            'can_decide' => $this->approvals->canDecide(),
        ]]);
    }

    public function show(ApprovalRequest $approvalRequest): ApprovalRequestResource
    {
        return (new ApprovalRequestResource($this->visibleOrFail($approvalRequest)->load(self::RELATIONS)))->withDetails();
    }

    public function approve(Request $request, ApprovalRequest $approvalRequest): ApprovalRequestResource
    {
        $data = $request->validate(['note' => ['nullable', 'string', 'max:1000']]);

        return (new ApprovalRequestResource($this->approvals->approve($approvalRequest, $data['note'] ?? null)->load(self::RELATIONS)))->withDetails();
    }

    public function reject(Request $request, ApprovalRequest $approvalRequest): ApprovalRequestResource
    {
        // The requester has to know why, so a rejection always carries its reason.
        $data = $request->validate(
            ['note' => ['required', 'string', 'min:5', 'max:1000']],
            ['note.required' => 'Alasan penolakan wajib diisi.', 'note.min' => 'Alasan penolakan minimal :min karakter.'],
        );

        return (new ApprovalRequestResource($this->approvals->reject($approvalRequest, $data['note'] ?? null)->load(self::RELATIONS)))->withDetails();
    }

    public function cancel(ApprovalRequest $approvalRequest): ApprovalRequestResource
    {
        return (new ApprovalRequestResource($this->approvals->cancel($this->visibleOrFail($approvalRequest))->load(self::RELATIONS)))->withDetails();
    }

    /** Filters shared by the list and its per-status counts. */
    private function filterRules(): array
    {
        return [
            'category' => ['nullable', Rule::in(array_keys(ApprovalActions::CATEGORIES))],
            'mine' => ['nullable', 'boolean'],
            'decided_by_me' => ['nullable', 'boolean'],
            'search' => ['nullable', 'string', 'max:100'],
        ];
    }

    /**
     * Visible requests under the list filters (all but status). The search matches the summary
     * (asset number, name), the requester's name and the type of change.
     */
    private function filtered(Request $request): Builder
    {
        return $this->visible($request->boolean('mine'))
            ->when($request->query('category'), fn ($q, $category) => $q->where('category', $category))
            ->when($request->boolean('decided_by_me'), fn ($q) => $q->where('decided_by', $this->tenancy->tenant()->userId()))
            ->when(trim((string) $request->query('search')), function ($q, $term) {
                $like = ListQuery::like($term);
                $actions = ApprovalActions::matching($term);
                $q->where(fn ($w) => $w->where('summary', 'ilike', $like)
                    ->orWhereHas('requester', fn ($u) => $u->where('name', 'ilike', $like))
                    ->when($actions !== [], fn ($w) => $w->orWhereIn('action', $actions)));
            });
    }

    private function visible(bool $mine): Builder
    {
        return ApprovalRequest::query()
            ->when($mine || ! $this->approvals->canDecide(), fn ($q) => $q->where('requested_by', $this->tenancy->tenant()->userId()));
    }

    /** Another user's request answers like a missing one for users who do not decide. */
    private function visibleOrFail(ApprovalRequest $approval): ApprovalRequest
    {
        if (! $this->visible(false)->whereKey($approval->id)->exists()) {
            throw new ApiException('NOT_FOUND', 'Data tidak ditemukan.', 404);
        }

        return $approval;
    }
}
