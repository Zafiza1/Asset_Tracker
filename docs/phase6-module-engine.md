# Phase 6: Module Engine

## Overview
Phase 6 adds the Module Engine (Sections 13–15): a platform module registry
with versions, and a per-project lifecycle to install, configure, enable,
disable, upgrade and uninstall modules. Design details are in
[architecture/modules.md](architecture/modules.md).

## Completed Components

### 1. Database Migrations
- **modules**: platform registry (slug, category, `is_core`, status).
- **module_versions**: immutable releases with dependencies, config schema,
  permissions and status.
- **project_modules**: tenant-scoped installation pinned to a version, with
  status, configuration and lifecycle timestamps. `UNIQUE(project_id, module_id)`.

### 2. Models
`Module`, `ModuleVersion`, `ProjectModule` (TenantScoping).
`Project::projectModules()` and `Project::hasModuleEnabled($slug)`.

### 3. Services
- **ModuleRegistry**: publishes manifests, keeps released versions immutable,
  registers module permissions, resolves lifecycle handlers.
- **ModuleService**: lifecycle transitions with row locking, dependency and
  reverse-dependency checks, config-schema validation, hooks and events.
- **VersionConstraint** (`app/Support`): semver constraint matching for dependencies.

### 4. Module Contract
`ModuleContract` hooks (`onInstall`, `onConfigure`, `onEnable`, `onDisable`,
`onUninstall`, `onUpgrade`), with `BaseModule` as the no-op default.

### 5. Catalog
`config/modules.php` holds the core modules (asset, location, movement) and
maintenance, inspection, customer, delivery, inventory and rental. They are
published with `php artisan modules:sync`, which `ModuleSeeder` also runs.
`ProjectModuleSeeder` enables demo modules on the seeded demo projects. That is
project configuration, not Core.

### 6. API Routes
```
GET    /api/v1/modules                                  catalog (search, category, sort, per_page)
GET    /api/v1/modules/{slug}                           catalog entry with versions

GET    /api/v1/project-modules                          modules of X-Project-Id (?status=)
POST   /api/v1/project-modules                          install {module, version?, configuration?}
GET    /api/v1/project-modules/{slug}
PUT    /api/v1/project-modules/{slug}/configuration     {configuration}
POST   /api/v1/project-modules/{slug}/enable
POST   /api/v1/project-modules/{slug}/disable
POST   /api/v1/project-modules/{slug}/upgrade           {version?, configuration?}
DELETE /api/v1/project-modules/{slug}                   uninstall
```
Lifecycle rule violations return `409`, invalid or incomplete configuration
returns `422` with `errors.configuration.<key>`, and unknown or not-installed
modules return `404`. All of them use the standard error format.

### 7. Middleware
`module:{slug}` (`EnsureModuleEnabled`) gates a module's routes on it being
enabled for the current project.

### 8. Events
`ModuleLifecycleChanged` is dispatched after commit with
`project.module.{installed|configured|enabled|disabled|upgraded|uninstalled}`.

### 9. Testing
- **ModuleEngineTest** (21 tests) covers: the catalog, the full lifecycle,
  reinstall, duplicate install, disable-before-uninstall, core modules,
  dependency enforcement in both directions, config validation, required
  settings, version pinning when a new version is published, upgrades with
  config migration, downgrade refusal, upgrades blocked by dependents,
  deprecated versions, permissions (viewer, project admin), project isolation,
  route gating, events, and hook-failure rollback.
- **VersionConstraintTest**: constraint matching.

## Other Changes
- `resolveProject()` was duplicated in `AssetController` and
  `LocationController`. It moved into the `Controllers\Concerns\ResolvesProject`
  trait, which `ProjectModuleController` uses too.

## Next Steps
Phase 7: Template Engine. Template versions, template modules, and applying a
template to a project through `ModuleService`.
