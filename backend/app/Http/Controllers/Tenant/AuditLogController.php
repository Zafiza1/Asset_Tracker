<?php

namespace App\Http\Controllers\Tenant;

use App\Domain\Audit\AuditEntityLabels;
use App\Domain\Audit\Models\AuditLog;
use App\Domain\Identity\Models\User;
use App\Domain\Shared\Tenancy\Tenancy;
use App\Http\Controllers\Controller;
use App\Http\Resources\AuditLogResource;
use App\Http\Support\AuditLogFilters;
use App\Http\Support\ListQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class AuditLogController extends Controller
{
    public function index(Request $request, Tenancy $tenancy): AnonymousResourceCollection
    {
        $query = AuditLog::query()->forOrganization($tenancy->organizationId())->with('actor');
        AuditLogFilters::apply($query, $request);
        // The module is the first part of the action code, so it sorts with the action.
        ListQuery::sort($query, $request, [
            'time' => 'created_at', 'action' => 'action', 'module' => 'action', 'object' => 'entity_type',
            'actor' => ListQuery::related(new AuditLog, 'actor', 'name'),
        ], '-time');

        $page = $query->paginate(min(100, ListQuery::perPage($request, 10)))->withQueryString();
        AuditEntityLabels::attach($page->getCollection());

        return AuditLogResource::collection($page);
    }

    /**
     * Everyone who appears as an actor in this organization's audit trail, for the "Pelaku" filter.
     * Taken from the log itself (not the member list), so former members stay filterable and
     * audit.view needs no user.view.
     */
    public function actors(Tenancy $tenancy): JsonResponse
    {
        $ids = AuditLog::query()->forOrganization($tenancy->organizationId())
            ->whereNotNull('actor_user_id')
            ->distinct()
            ->pluck('actor_user_id');

        $actors = User::query()->whereIn('id', $ids)->orderBy('name')->get(['id', 'name', 'email'])
            ->map(fn (User $u) => ['id' => $u->id, 'name' => $u->name, 'email' => $u->email]);

        return response()->json(['data' => $actors]);
    }
}
