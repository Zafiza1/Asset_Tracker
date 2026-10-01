# Phase 3: Authorization Implementation

## Overview
Phase 3 implements the comprehensive authorization system for the Asset Tracker PaaS, providing role-based access control (RBAC), permission-based authorization, and tenant isolation mechanisms.

## Completed Components

### 1. Database Migrations
- **roles table**: System and custom roles with hierarchical levels
- **permissions table**: Granular permissions organized by modules
- **role_permissions table**: Many-to-many relationship between roles and permissions
- **user_roles table**: Role assignments with tenant context (organization/project)
- **user_permissions table**: Direct permission assignments with tenant context

### 2. Models
- **Role Model**: Role management with permission assignment methods
- **Permission Model**: Permission management with module organization
- **User Model Updates**: Comprehensive role/permission helper methods

### 3. Role & Permission System
- **8 System Roles**:
  - Platform Admin (level 100)
  - Organization Owner (level 90)
  - Project Admin (level 80)
  - Module Manager (level 75)
  - Integration Manager (level 70)
  - Manager (level 60)
  - Operator (level 40)
  - Viewer (level 20)

- **85+ System Permissions** across modules:
  - Organization management
  - Project management
  - Asset operations
  - Location management
  - Movement tracking
  - Device management
  - Integration management
  - Module management
  - Template management
  - User management
  - Role management
  - Permission management
  - Reporting
  - Audit logging

### 4. Authorization Middleware
- **TenantMiddleware**: Validates tenant context and access
- **PermissionMiddleware**: Permission-based route protection
- **RoleMiddleware**: Role-based route protection

### 5. Policies
- **OrganizationPolicy**: Organization CRUD and user management
- **ProjectPolicy**: Project CRUD and user management
- **UserPolicy**: User management with self-access rules

### 6. Authentication Service
- **AuthService**: Complete authentication workflow
  - Login/logout
  - Token management
  - Tenant context switching
  - Permission verification

### 7. API Routes
- **Authentication endpoints**:
  - POST /auth/login
  - POST /auth/register
  - POST /auth/logout
  - POST /auth/logout-all
  - POST /auth/refresh
  - GET /auth/me
  - POST /auth/switch-organization
  - POST /auth/switch-project

### 8. Testing
- **AuthServiceTest**: Authentication service functionality
- **TenantIsolationTest**: Multi-tenant isolation verification
- **PermissionTest**: Permission system verification
- **PolicyTest**: Policy authorization verification

### 9. Seeders
- **RoleAndPermissionSeeder**: System roles and permissions
- **UserSeeder Updates**: Platform admin and role assignments
- **ProjectSeeder Updates**: Project-level role assignments

## Key Features

### Tenant Context Support
- Roles and permissions can be assigned at different levels:
  - Global (platform-wide)
  - Organization level
  - Project level
- Context-aware permission checking
- Tenant-scoped queries via TenantScoping trait

### Permission System
- **Direct permissions**: User-specific permission grants
- **Role-based permissions**: Permissions inherited from roles
- **Permission hierarchy**: Higher level roles have more permissions
- **System protection**: System roles/permissions cannot be deleted

### Authorization Flow
1. User authenticates via API
2. Tenant context established (organization/project)
3. Middleware validates access
4. Policies check permissions
5. Actions authorized or denied

### Platform Admin Bypass
- Platform admin role bypasses all authorization checks
- Full access to all organizations, projects, and resources

## Architecture Compliance

### Multi-Tenancy
- ✅ Tenant isolation at organization and project level
- ✅ Context-aware role/permission assignments
- ✅ Tenant-scoped database queries
- ✅ Cross-tenant access prevention

### Security
- ✅ Role-based access control
- ✅ Permission-based authorization
- ✅ Policy-based resource protection
- ✅ API authentication via Sanctum
- ✅ Token management

### Extensibility
- ✅ Custom roles can be created
- ✅ Custom permissions can be added
- ✅ Modular permission structure
- ✅ System role/permission protection

### Generic Design
- ✅ No customer-specific hardcoding
- ✅ Universal permission structure
- ✅ Configurable role hierarchy
- ✅ Tenant-agnostic core authorization

## API Usage Examples

### Login
```bash
POST /auth/login
{
  "email": "admin@platform.com",
  "password": "password",
  "device_name": "web"
}
```

### Switch Organization Context
```bash
POST /auth/switch-organization
Headers: Authorization: Bearer {token}
{
  "organization_id": 1
}
```

### Protected Route with Permission
```php
Route::middleware(['auth:sanctum', 'permission:asset.create'])
    ->post('/api/v1/assets', [AssetController::class, 'store']);
```

### Protected Route with Role
```php
Route::middleware(['auth:sanctum', 'role:manager'])
    ->post('/api/v1/assets', [AssetController::class, 'store']);
```

## Testing

Run the authorization tests:
```bash
cd backend
php artisan test --filter AuthServiceTest
php artisan test --filter TenantIsolationTest
php artisan test --filter PermissionTest
php artisan test --filter PolicyTest
```

## Next Steps

Phase 3 is complete. The authorization system provides:
- Secure multi-tenant access control
- Granular permission management
- Role-based authorization
- Tenant context isolation
- API authentication

Ready for Phase 4: Asset Core implementation.
