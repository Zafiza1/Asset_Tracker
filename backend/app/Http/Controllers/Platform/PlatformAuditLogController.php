<?php

namespace App\Http\Controllers\Platform;

use App\Domain\Audit\Models\AuditLog;
use App\Http\Controllers\Controller;
use App\Http\Resources\AuditLogResource;
use App\Http\Support\AuditLogFilters;
use App\Http\Support\ListQuery;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class PlatformAuditLogController extends Controller
{
    /** Platform audit: platform-level events, or one organization's events when filtered. */
    public function index(Request $request): AnonymousResourceCollection
    {
        $request->validate(['organization_id' => ['nullable', 'uuid']]);

        $query = AuditLog::query()->with('actor');
        $orgId = $request->query('organization_id');
        $orgId ? $query->where('organization_id', $orgId) : $query->whereNull('organization_id');
        AuditLogFilters::apply($query, $request);

        return AuditLogResource::collection($query->paginate(ListQuery::perPage($request, 50))->withQueryString());
    }
}
