<?php

namespace App\Http\Controllers\Tenant;

use App\Domain\Assets\AssetStatus;
use App\Domain\AssetTransactions\Flows\FlowActions;
use App\Domain\AssetTransactions\Flows\FlowGraph;
use App\Domain\AssetTransactions\Services\TransactionAttachments;
use App\Domain\AssetTransactions\Types\TransactionFieldTypes;
use App\Domain\Authorization\Models\Role;
use App\Domain\Authorization\RoleTemplates;
use App\Domain\Identity\Models\User;
use App\Domain\Shared\Tenancy\Tenancy;
use App\Domain\TenantMaster\Services\CategoryAttributeService;
use App\Http\Controllers\Controller;
use App\Http\Support\ListQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Choices for transaction forms and the flow editor (F-07). Members are given by name only, so
 * a transaction user picking a "Pengguna" field sees no email or other personal data.
 */
class TransactionOptionsController extends Controller
{
    private const MAX_MEMBERS = 50;

    public function __construct(private readonly Tenancy $tenancy) {}

    /** Active members for a user field (search by name); transaction.view. */
    public function members(Request $request): JsonResponse
    {
        $request->validate(['search' => ['nullable', 'string', 'max:100'], 'ids' => ['nullable', 'string', 'max:2000']]);

        return response()->json(['data' => $this->memberRows(trim((string) $request->query('search')), array_filter(explode(',', (string) $request->query('ids'))))]);
    }

    /** Everything the flow editor offers; transaction_flow.manage. */
    public function flow(): JsonResponse
    {
        $conditions = collect(CategoryAttributeService::DEFAULT_ATTRIBUTES['status_condition'])
            ->firstWhere('label', CategoryAttributeService::CONDITION_LABEL)['options'] ?? [];

        return response()->json(['data' => [
            'roles' => Role::query()->orderBy('name')->get(['id', 'code', 'name', 'template_code'])->map(fn (Role $r) => [
                'id' => $r->id, 'name' => $r->name, 'may_approve' => RoleTemplates::mayHoldApproval($r->template_code),
            ])->values(),
            'members' => $this->memberRows('', [], approversFlag: true),
            'node_types' => FlowGraph::NODE_TYPES,
            'subjects' => FlowGraph::SUBJECTS,
            'operators' => FlowGraph::OPERATORS,
            'action_kinds' => FlowActions::KINDS,
            'action_statuses' => array_map(fn (string $s) => ['value' => $s, 'label' => AssetStatus::LABELS[$s]], FlowGraph::ACTION_STATUSES),
            'asset_statuses' => array_map(fn (string $s, string $label) => ['value' => $s, 'label' => $label], array_keys(AssetStatus::LABELS), AssetStatus::LABELS),
            'conditions' => $conditions,
            'field_types' => TransactionFieldTypes::TYPES,
            'attachment_kinds' => TransactionAttachments::KIND_LABELS,
            'max_approvals' => FlowGraph::MAX_APPROVALS,
            'max_message' => FlowActions::MAX_MESSAGE,
        ]]);
    }

    /**
     * @param  list<string>  $ids  members to include whatever the search (the values already chosen)
     * @return list<array{id: string, name: string, may_approve?: bool}>
     */
    private function memberRows(string $search, array $ids, bool $approversFlag = false): array
    {
        $orgId = $this->tenancy->organizationId();
        $rows = User::query()->join('organization_members as m', 'm.user_id', '=', 'users.id')
            ->where('m.organization_id', $orgId)->where('m.status', User::STATUS_ACTIVE)
            ->where(fn ($w) => $w->when($search !== '', fn ($q) => $q->where('users.name', 'ilike', ListQuery::like($search)))
                ->when($ids !== [], fn ($q) => $q->orWhereIn('users.id', $ids)))
            ->orderBy('users.name')->limit(self::MAX_MEMBERS + count($ids))->get(['users.id', 'users.name']);
        if (! $approversFlag) {
            return $rows->map(fn ($u) => ['id' => $u->id, 'name' => $u->name])->all();
        }
        $approvers = DB::table('user_roles as ur')->join('roles as r', 'r.id', '=', 'ur.role_id')
            ->where('ur.organization_id', $orgId)->whereIn('r.template_code', [RoleTemplates::SUPER_ADMIN, RoleTemplates::SUPERVISOR])
            ->pluck('ur.user_id')->flip();

        return $rows->map(fn ($u) => ['id' => $u->id, 'name' => $u->name, 'may_approve' => isset($approvers[$u->id])])->all();
    }
}
