<?php

namespace App\Http\Controllers\Tenant;

use App\Domain\AssetTransactions\Flows\FlowDescriber;
use App\Domain\AssetTransactions\Flows\RunAccess;
use App\Domain\AssetTransactions\Models\TransactionRun;
use App\Domain\AssetTransactions\Models\TransactionTypeDefinition;
use App\Domain\AssetTransactions\Services\AssetTransactionService;
use App\Domain\AssetTransactions\Templates\TransactionTemplates;
use App\Domain\AssetTransactions\TransactionType;
use App\Domain\Shared\Tenancy\Tenancy;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

/**
 * Transaction Center (F-08 §3.9, D-50): the transactions the user may record now, grouped as in
 * the template gallery, each with its "Cara kerja" in short (F08-24), and how many tasks wait for
 * the user. A type shows only when it is active, the user holds what recording it needs
 * (transaction.create and, for a type with a core action, that built-in transaction's permission)
 * and the start step's roles let them start it (F08-41).
 */
class TransactionCenterController extends Controller
{
    /** The gallery group of each built-in transaction. */
    private const SYSTEM_GROUPS = [
        TransactionType::TRANSFER => 'perpindahan',
        TransactionType::OUTGOING => 'perpindahan',
        TransactionType::INCOMING => 'perpindahan',
        TransactionType::LOAN => 'peminjaman',
        TransactionType::RETURN => 'peminjaman',
        TransactionType::STATUS_CHANGE => 'perawatan',
        TransactionType::NOTE => 'lainnya',
    ];

    public function __construct(
        private readonly Tenancy $tenancy,
        private readonly RunAccess $access,
        private readonly FlowDescriber $describer,
    ) {}

    public function index(): JsonResponse
    {
        $tenant = $this->tenancy->tenant();
        $userId = $tenant->userId();
        $roles = $this->access->userRoles($userId);
        $types = TransactionTypeDefinition::query()->where('status', TransactionTypeDefinition::ACTIVE)
            ->with(['activeFlow', 'categories:id,name'])->withCount('fields')->orderBy('name')->get();

        $cards = [];
        foreach ($types as $type) {
            $core = $type->coreType();
            $permission = $core === null ? null : AssetTransactionService::permissionFor($core);
            if (! $tenant->can('transaction.create') || ($permission !== null && ! $tenant->can($permission))) {
                continue;
            }
            $graph = $type->activeFlow?->graph;
            $start = $graph === null ? null : collect($graph['nodes'])->firstWhere('type', 'start');
            $allowed = (array) ($start['config']['roles'] ?? []);
            if ($allowed !== [] && array_intersect($allowed, $roles) === []) {
                continue;
            }
            $cards[] = [
                'id' => $type->id,
                'code' => $type->code,
                'name' => $type->name,
                'description' => $type->description,
                'icon' => $type->icon,
                'system_type' => $type->system_type,
                'core_type' => $type->core_type,
                'multi_asset' => $type->multi_asset,
                'field_count' => $type->fields_count,
                'categories' => $type->categories->map(fn ($c) => ['id' => $c->id, 'name' => $c->name])->values(),
                'group' => $this->group($type),
                'flow_version' => $type->activeFlow?->version_number,
                'needs_approval' => $graph !== null && collect($graph['nodes'])->contains('type', 'approval'),
                'summary' => $graph === null ? 'Langsung tercatat.' : $this->describer->describe($type, $graph)['summary'],
            ];
        }

        return response()->json([
            'data' => $cards,
            'meta' => [
                'groups' => [...TransactionTemplates::CATEGORIES, 'lainnya' => 'Lainnya'],
                'tasks' => $this->access->openTasks()->count(),
                'running' => $this->access->visible()->where('created_by', $userId)->where('state', TransactionRun::RUNNING)->count(),
            ],
        ]);
    }

    private function group(TransactionTypeDefinition $type): string
    {
        $template = $type->source_template_key === null ? null : TransactionTemplates::find($type->source_template_key);
        if ($template !== null) {
            return $template['category'];
        }
        $core = $type->coreType();

        return $core === null ? 'lainnya' : (self::SYSTEM_GROUPS[$core] ?? 'lainnya');
    }
}
