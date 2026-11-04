<?php

namespace App\Http\Middleware;

use App\Domain\Approvals\ApprovalActions;
use App\Domain\Approvals\Services\ApprovalService;
use App\Http\Resources\ApprovalRequestResource;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * Usage: ->middleware('approval:asset.update') on a write route (see ApprovalActions).
 *
 * Users with approval.manage (Super Admin, Supervisor) pass through: their changes apply
 * directly. For everyone else the change is tried out in a transaction that is always rolled
 * back, so invalid input and broken rules are answered right away exactly as without approval;
 * a change that would succeed is stored as a pending approval request and answered with
 * 202 { data: <request>, meta: { approval_pending: true } }. Runs after route model binding.
 */
final class RequireApproval
{
    public function __construct(private readonly ApprovalService $approvals) {}

    public function handle(Request $request, Closure $next, string $action): Response
    {
        if ($this->approvals->canDecide()) {
            return $next($request);
        }

        $approval = $this->approvals->draft($request, $action);
        if (ApprovalActions::triedAtSubmission($action)) {
            DB::beginTransaction();
            try {
                $response = $next($request);
            } finally {
                DB::rollBack();
            }
            if (! $response->isSuccessful()) {
                return $response;
            }
        }

        $approval = $this->approvals->submit($approval, $request);

        return (new ApprovalRequestResource($approval->load('requester')))
            ->additional(['meta' => [
                'approval_pending' => true,
                'message' => 'Perubahan diajukan dan menunggu persetujuan Super Admin atau Supervisor.',
            ]])
            ->response()
            ->setStatusCode(202);
    }
}
