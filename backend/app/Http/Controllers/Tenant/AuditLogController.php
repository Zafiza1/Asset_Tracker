<?php

namespace App\Http\Controllers\Tenant;

use App\Domain\Audit\Models\AuditLog;
use App\Domain\Shared\Tenancy\Tenancy;
use App\Http\Controllers\Controller;
use App\Http\Resources\AuditLogResource;
use App\Http\Support\AuditLogFilters;
use App\Http\Support\ListQuery;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class AuditLogController extends Controller
{
    public function index(Request $request, Tenancy $tenancy): AnonymousResourceCollection
    {
        $query = AuditLog::query()->forOrganization($tenancy->organizationId())->with('actor');
        AuditLogFilters::apply($query, $request);

        return AuditLogResource::collection($query->paginate(ListQuery::perPage($request, 50))->withQueryString());
    }
}
