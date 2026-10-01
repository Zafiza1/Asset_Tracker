# Multi-Tenancy Architecture

## Overview

Asset Tracker PaaS implements strong multi-tenancy to ensure complete data and configuration isolation between organizations (tenants).

## Tenant Hierarchy

```
Platform
│
└── Organization (Tenant)
    │
    ├── Users
    ├── Roles
    ├── Permissions
    │
    └── Projects
        │
        ├── Assets
        ├── Locations
        ├── Movements
        ├── Devices
        ├── Integrations
        ├── Custom Fields
        └── Settings
```

## Isolation Levels

### 1. Data Isolation

All tenant-sensitive entities include:
- `organization_id` - Organization-level entities
- `project_id` - Project-level entities

**Examples:**

```php
// Organization-level
organizations
organization_users
roles
permissions

// Project-level
projects
project_users
assets
locations
movements
devices
integrations
```

### 2. Configuration Isolation

Each organization and project has isolated configuration:
- Organization settings
- Project settings
- Module configurations
- Integration configurations
- Custom field definitions

### 3. User Isolation

Users belong to organizations and can be assigned to specific projects:
- A user can only access data from their organization
- A user can only access projects they are assigned to
- Cross-organization access is impossible

## Database Scoping

### Global Queries

All queries must include tenant scoping:

```php
// ❌ WRONG - No tenant scoping
$asset = Asset::find($id);

// ✅ CORRECT - With tenant scoping
$asset = Asset::where('organization_id', $user->organization_id)
              ->where('project_id', $user->project_id)
              ->find($id);
```

### Query Scopes

Use Laravel query scopes for consistent scoping:

```php
class Asset extends Model
{
    public function scopeForOrganization($query, $organizationId)
    {
        return $query->where('organization_id', $organizationId);
    }

    public function scopeForProject($query, $projectId)
    {
        return $query->where('project_id', $projectId);
    }
}
```

### Global Scopes

Every model using the `App\Traits\TenantScoping` trait automatically registers
`App\Models\Scopes\TenantScope`, an Eloquent global scope that constrains
queries to `organization_id`/`project_id` — whichever columns the model
actually has:

```php
class Asset extends Model
{
    use TenantScoping; // auto-applies the organization_id/project_id filter
}
```

The scope reads the current request's `App\Tenancy\TenantContext` (a
container singleton populated by `App\Middleware\TenantMiddleware` after it
validates the caller's membership — see "Tenancy in API" below). It only
engages once that context has been set; outside of a tenant-scoped request
(console commands, jobs, tests that never populate it) it stays inert so it
never masks a legitimate system-level query. Platform admins bypass it
entirely, mirroring the `Gate::before` bypass in `AuthServiceProvider`.

This is defense-in-depth on top of, not a replacement for, per-resource
Policy checks (`canAccessProject()`, `hasPermission()`) and the `tenant`
route middleware — all business (`/api/v1/...`) routes must run through
`tenant` middleware for the scope to have anything to filter on.

## Authorization Chain

Access checks must follow the full chain:

```
User
    ↓
Organization (User belongs to this organization?)
    ↓
Project (User has access to this project?)
    ↓
Resource (Resource belongs to user's organization/project?)
```

### Role scope and inheritance

Roles and direct permissions are granted at one of three levels
(`user_roles` / `user_permissions`):

| organization_id | project_id | Level | Applies to |
|---|---|---|---|
| NULL | NULL | Platform | Everything (Platform Admin) |
| set | NULL | Organization | The organization **and every project in it** |
| set | set | Project | That project only |

`User::hasPermission($slug, $organizationId, $projectId)` with both ids set
matches project-level grants for that project plus organization-level grants
of that organization. A project is therefore accessible (`canAccessProject()`)
to its members and to organization members holding any organization-level
role. A plain organization member (no organization-level role) only sees the
projects they were added to.

An organization-level grant never reaches another organization's project:
callers always pass a project together with its own `organization_id`, and
`TenantMiddleware` refuses a context whose project belongs to a different
organization.

### Membership management

Nobody can grant a role above their own level, or change or remove a member
who outranks them, in the same context (`App\Services\RoleAssignment`).
Platform Admin cannot be granted through memberships, and Organization Owner
cannot be granted at project level. An organization always keeps at least
one owner. Removing someone from an organization also removes them from all
of its projects and revokes every grant they held there.

### Policy Example

```php
class AssetPolicy
{
    public function update(User $user, Asset $asset): bool
    {
        if ($user->isPlatformAdmin()) {
            return true;
        }

        if (!$user->canAccessProject($asset->project_id)) {
            return false;
        }

        return $user->hasPermission('asset.update', $asset->organization_id, $asset->project_id);
    }
}
```

## Serial Number Scoping

Serial numbers are unique within a project, not globally:

```php
// Unique constraint
UNIQUE(project_id, serial_number)

// Example validation
Project A: 00001, 00002, 00003
Project B: 00001, 00002, 00003
// No conflict because different projects
```

## Tenancy in API

### Header-based Identification

For API requests, `TenantMiddleware` resolves organization/project context as:

1. **`X-Organization-Id` / `X-Project-Id` headers** (or matching query params) — explicit, per-request
2. **User's persisted default** (`users.default_organization_id`/`default_project_id`, set via `/auth/switch-organization` and `/auth/switch-project`) — used when no header is sent

Either way, membership is validated (`canAccessOrganization()`/`canAccessProject()`) before the context is trusted, returning 403 on mismatch. Context is stored in a request-scoped container singleton (`TenantContext`), not the session, so it works for stateless Sanctum bearer-token clients (mobile apps, server-to-server integrations), not just cookie-based sessions.

Further rules:

- The project must belong to the organization in context. A mismatch sent
  explicitly gets a 403. A mismatching *default* project is dropped, so the
  request continues with the organization only.
- With only a project given, the organization is taken from the project.
- The context is cleared at the start of every request, so nothing carries
  over between requests on long-running workers.
- `permission:` and `role:` route middleware read the validated
  `TenantContext`, never the raw headers, so they must run after `tenant`.

### Control Plane routes

Organization, project and membership endpoints (`/api/v1/organizations/...`,
`/api/v1/projects/{id}/...`) name their tenant in the URL. They run under the
`control-plane` middleware instead of `tenant`. That middleware keeps
`TenantContext` empty, so a user's default organization cannot hide the
organization they are addressing. The policies authorize every request, and
listings filter explicitly by organization and membership.

### API Response Filtering

Never return data from other tenants:

```php
// ❌ WRONG
return Asset::all();

// ✅ CORRECT
return Asset::forOrganization($user->organization_id)
             ->forProject($user->project_id)
             ->get();
```

## Tenancy Testing

### Test Cases

1. **Tenant Isolation**: User from Organization A cannot access Organization B's data
2. **Project Isolation**: User with access to Project A cannot access Project B
3. **Cross-tenant Prevention**: API calls with wrong tenant context are rejected
4. **Serial Number Scoping**: Serial numbers can duplicate across projects

### Example Test

```php
public function test_tenant_isolation()
{
    $orgA = Organization::factory()->create();
    $orgB = Organization::factory()->create();
    
    $userA = User::factory()->forOrganization($orgA)->create();
    $userB = User::factory()->forOrganization($orgB)->create();
    
    $assetA = Asset::factory()->forOrganization($orgA)->create();
    $assetB = Asset::factory()->forOrganization($orgB)->create();
    
    // User A should not see Asset B
    $this->actingAs($userA)
         ->getJson("/api/v1/assets/{$assetB->id}")
         ->assertForbidden();
}
```

## Audit Logging

All tenant-sensitive operations must be logged:

```php
ActivityLog::create([
    'organization_id' => $user->organization_id,
    'project_id' => $user->project_id,
    'user_id' => $user->id,
    'action' => 'asset.created',
    'resource_type' => 'Asset',
    'resource_id' => $asset->id,
    'ip_address' => request()->ip(),
]);
```

## Security Considerations

1. **IDOR Prevention**: Always verify tenant ownership before returning data
2. **No Cross-tenant Queries**: Never query across organizations
3. **Tenant Context**: Maintain tenant context throughout request lifecycle
4. **Resource Scoping**: All resources scoped to tenant/project
5. **Permission Scoping**: Permissions checked within tenant context

## Performance

### Indexing

Ensure proper indexes for tenant columns:

```php
$table->index(['organization_id', 'project_id']);
$table->index(['organization_id', 'created_at']);
$table->index(['project_id', 'status']);
```

### Query Optimization

- Use composite indexes for common query patterns
- Avoid N+1 queries with tenant relationships
- Consider database partitioning for large multi-tenant datasets

## Future Considerations

### Database per Tenant

For very large scale, consider:
- Database per tenant
- Schema per tenant
- Connection pooling per tenant

### Runtime Isolation

For extreme isolation requirements:
- Separate runtime per tenant
- Separate API instances per tenant
- Separate worker pools per tenant

## Summary

Multi-tenancy is enforced at:
- Database level (foreign keys, unique constraints)
- Application level (query scopes, policies)
- API level (authentication, authorization)
- Business logic level (all operations scoped)

This defense-in-depth approach ensures complete tenant isolation.
