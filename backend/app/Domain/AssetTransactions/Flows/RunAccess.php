<?php

namespace App\Domain\AssetTransactions\Flows;

use App\Domain\Assets\AssetScope;
use App\Domain\Assets\Models\Asset;
use App\Domain\AssetTransactions\Models\TransactionRun;
use App\Domain\AssetTransactions\Models\TransactionTask;
use App\Domain\Authorization\Models\Role;
use App\Domain\Authorization\RoleTemplates;
use App\Domain\Shared\Tenancy\Tenancy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Who sees a run and whose task a step is (F-07 §5, §7), for the current user.
 *
 * A run is visible when one of its assets is in the user's data scope, or the user started it
 * or has its open task. A task belongs to its user, to the holders of its role, or, for a
 * location_supervisor approval, to every member who may approve (Super Admin or Supervisor)
 * and whose data scope covers all of the run's assets. A user who already approved a task that
 * needs several approvers is no longer asked.
 *
 * F-08i: an asset_custodian approval belongs to the custodians listed on the task (members who
 * need no approval role, D-57, and see the run only while it is open for them), and, while the
 * Supervisor's part is still open, to those who may approve the assets without a custodian. A
 * department_supervisor approval belongs to Super Admins and Supervisors whose data scope is the
 * organization or includes that department.
 */
final class RunAccess
{
    public function __construct(
        private readonly Tenancy $tenancy,
        private readonly AssetScope $scope,
    ) {}

    public function visible(): Builder
    {
        return TransactionRun::query()->where(fn ($w) => $w
            ->whereHas('assets', fn ($a) => $a->whereIn('assets.id', $this->scope->apply(Asset::query(), 'assets.location_id')->select('assets.id')))
            ->orWhere('created_by', $this->userId())
            ->orWhereIn('id', $this->openTasks()->select('run_id')));
    }

    /** Open tasks the current user may work on now. */
    public function openTasks(): Builder
    {
        $userId = $this->userId();
        $roles = $this->userRoles($userId);
        $mayApprove = $this->mayApprove($roles);
        $scope = $this->tenancy->tenant()->scope();
        $coversAssets = fn ($q) => $q->whereNotExists(fn ($e) => $e->from('transaction_run_assets as ra')->whereColumn('ra.run_id', 'transaction_tasks.run_id')
            ->whereNotIn('ra.asset_id', $this->scope->apply(Asset::query())->select('id')));

        return TransactionTask::query()->where('transaction_tasks.status', TransactionTask::OPEN)
            ->whereRaw('NOT (transaction_tasks.approved_by @> ?::jsonb)', [json_encode([$userId])])
            ->where(fn ($w) => $w
                ->where(fn ($u) => $u->where('assignee_type', 'user')->where('assignee_id', $userId))
                ->orWhere(fn ($r) => $r->where('assignee_type', 'role')->whereIn('assignee_id', $roles))
                ->when($mayApprove, fn ($q) => $q->orWhere(fn ($l) => $coversAssets($l->where('assignee_type', 'location_supervisor'))))
                ->orWhere(fn ($c) => $c->where('assignee_type', 'asset_custodian')->where(fn ($who) => $who
                    ->whereRaw("transaction_tasks.details->'custodians' @> ?::jsonb", [json_encode([$userId])])
                    ->when($mayApprove, fn ($q) => $q->orWhere(fn ($f) => $coversAssets($f->whereRaw("(transaction_tasks.details->>'fallback')::boolean IS TRUE")
                        ->whereRaw("transaction_tasks.details->>'fallback_by' IS NULL"))))))
                ->when($mayApprove, fn ($q) => $q->orWhere(fn ($d) => $d->where('assignee_type', 'department_supervisor')
                    ->when(! $scope->organizationWide, fn ($w) => $w->whereIn('assignee_id', $scope->departmentIds === [] ? [null] : $scope->departmentIds)))));
    }

    public function isAssignee(TransactionTask $task): bool
    {
        return $this->openTasks()->whereKey($task->id)->exists();
    }

    /** @return list<string> */
    public function userRoles(string $userId): array
    {
        return DB::table('user_roles')->where('organization_id', $this->tenancy->organizationId())
            ->where('user_id', $userId)->pluck('role_id')->all();
    }

    /** @param list<string> $roleIds */
    private function mayApprove(array $roleIds): bool
    {
        return $roleIds !== [] && Role::query()->whereKey($roleIds)->pluck('template_code')
            ->contains(fn (?string $code) => RoleTemplates::mayHoldApproval($code));
    }

    private function userId(): string
    {
        return $this->tenancy->tenant()->userId();
    }
}
