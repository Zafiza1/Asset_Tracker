<?php

namespace App\Models\Scopes;

use App\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema;

/**
 * Automatically constrains queries on any model using the TenantScoping
 * trait to the organization/project resolved for the current request.
 *
 * The scope only engages once TenantContext has been populated (normally by
 * TenantMiddleware, after it has already verified the user belongs to that
 * organization/project) — outside of a request with tenant context (console
 * commands, tests, jobs that haven't set one explicitly) it stays inert so it
 * never masks a legitimate system-level query. Business routes must run
 * through the `tenant` middleware to get the protection: it exists to stop a
 * forgotten `->where('organization_id', ...)` on an otherwise-scoped request
 * from leaking another tenant's row (see docs/architecture/tenancy.md).
 *
 * Platform admins bypass it entirely, mirroring the Gate::before bypass in
 * AuthServiceProvider.
 */
class TenantScope implements Scope
{
    /** @var array<string, array<string, bool>> */
    protected static array $columnCache = [];

    public function apply(Builder $builder, Model $model): void
    {
        $user = Auth::user();

        if ($user && method_exists($user, 'isPlatformAdmin') && $user->isPlatformAdmin()) {
            return;
        }

        $context = app(TenantContext::class);
        $table = $model->getTable();

        if ($context->hasOrganization() && $this->hasColumn($table, 'organization_id')) {
            $builder->where($model->qualifyColumn('organization_id'), $context->organizationId());
        }

        if ($context->hasProject() && $this->hasColumn($table, 'project_id')) {
            $builder->where($model->qualifyColumn('project_id'), $context->projectId());
        }
    }

    protected function hasColumn(string $table, string $column): bool
    {
        return self::$columnCache[$table][$column]
            ??= Schema::hasColumn($table, $column);
    }
}
