# Module System Architecture

## Overview

A **module** is business functionality a project can install, configure and
enable without any change to Core (Section 13). A module is not an
**integration**: modules are business capabilities (Maintenance, Delivery,
Rental), and integrations are external technology (RFID, GPS, ERP). The two
never share tables or code paths.

| Level | Examples | Managed by the module engine? |
|---|---|---|
| Core (always on) | asset, location, movement | Listed in the registry with `is_core = true`. Cannot be installed, disabled or uninstalled. `Project::hasModuleEnabled()` is always true for them. |
| Installable | maintenance, inspection, customer, delivery, inventory, rental | Full lifecycle per project |
| Custom (future) | built with the Module Builder | Same lifecycle; will register through `ModuleRegistry::register()` |

## Lifecycle

```text
AVAILABLE ──install──▶ INSTALLED ──configure──▶ CONFIGURED ──enable──▶ ENABLED
                           │                                        ▲    │
                           └─────────────────enable─────────────────┘    │ disable
                                                                         ▼
                        UNINSTALLED ◀──────uninstall─────────────── DISABLED
```

| Transition | Allowed from | Checks |
|---|---|---|
| install | not installed / uninstalled | module available, version published, dependencies installed at matching versions, configuration valid |
| configure | installed, configured, enabled, disabled | schema-valid; stays complete if enabled. `installed` becomes `configured` |
| enable | installed, configured, disabled | all `required` settings present, dependencies **enabled** |
| disable | enabled | no enabled module depends on it |
| uninstall | installed, configured, disabled | not enabled, no installed module depends on it |
| upgrade | any installed state | target is newer and published, its dependencies hold, dependents' constraints accept the new version |

"AVAILABLE" is not stored: a module that has no `project_modules` row for the
project (or has one with status `uninstalled`) is available.

Uninstalling keeps the `project_modules` row with status `uninstalled` for
traceability; reinstalling reuses it with fresh defaults.

## Versioning (Section 15)

- Each release is an immutable `module_versions` row with its own
  `dependencies`, `config_schema` and `permissions`.
- A project is pinned to a `module_version_id`. Publishing a new version never
  changes an existing project; the API reports `upgrade_available` instead.
- New installs get the latest **published** version unless a version is given.
- A `deprecated` version keeps running for projects already on it but cannot be
  newly installed or targeted by an upgrade.
- Upgrades only go forward (no downgrades). Configuration carries over for keys
  the new version still defines, new defaults fill in the rest, and any
  settings passed with the upgrade are applied on top.
- `ModuleRegistry::register()` refuses to change the dependencies, schema or
  permissions of an existing version; it only updates its changelog and status.
  To change a module, publish a new version.

## Database Schema

| Table | Scope | Purpose |
|---|---|---|
| `modules` | platform | slug, name, category, `is_core`, status (`available`/`deprecated`) |
| `module_versions` | platform | version, changelog, dependencies, config_schema, permissions, status (`published`/`deprecated`) |
| `project_modules` | tenant (`organization_id`, `project_id`) | module_version pin, status, configuration, installed_by, lifecycle timestamps. `UNIQUE(project_id, module_id)` |

`ProjectModule` uses the `TenantScoping` trait, so every query in a request is
constrained to the current organization and project.

## Dependencies

Declared per version as `{"module-slug": "constraint"}`, for example
`{"customer": "^1.0", "movement": ">=1.0.0"}`. Supported constraints: `*`,
`1.2.0`, `=`, `>=`, `>`, `<=`, `<`, `^`, and comma- or space-separated
combinations (`App\Support\VersionConstraint`). Core dependencies are always
satisfied by the core module's latest published version.

## Configuration Schema

```php
'config_schema' => [
    'default_interval_days' => ['type' => 'integer', 'label' => 'Default interval (days)', 'default' => 30, 'min' => 1],
    'mode'                  => ['type' => 'string', 'options' => ['manual', 'auto'], 'default' => 'manual'],
    'currency'              => ['type' => 'string', 'required' => true],
],
```

Types: `string`, `text`, `integer`, `number`, `boolean`, `array`, `object`.
Optional keys: `label`, `description`, `required`, `default`, `options`, `min`,
`max`. Unknown settings are rejected. `required` is enforced when the module is
enabled (and while it stays enabled), so a module can be installed first and
configured afterwards.

## Components

| Component | Location | Role |
|---|---|---|
| Catalog | `config/modules.php` | Platform-published module manifests |
| `ModuleRegistry` | `app/Services/ModuleRegistry.php` | Publishes manifests (`php artisan modules:sync`), registers module permissions, resolves lifecycle handlers |
| `ModuleService` | `app/Services/ModuleService.php` | Runs every lifecycle transition in a locked transaction |
| `ModuleContract` | `app/Modules/Contracts/ModuleContract.php` | Lifecycle hooks: `onInstall`, `onConfigure`, `onEnable`, `onDisable`, `onUninstall`, `onUpgrade` |
| `BaseModule` | `app/Modules/BaseModule.php` | No-op handler; the default for modules without one |
| `EnsureModuleEnabled` | `app/Middleware/EnsureModuleEnabled.php` | `module:{slug}` route middleware |
| `ProjectModulePolicy` | `app/Policies/ProjectModulePolicy.php` | Permission checks per action |
| `ModuleLifecycleChanged` | `app/Events/ModuleLifecycleChanged.php` | `project.module.*` events after commit |

### Lifecycle hooks

Register a handler in `config/modules.php` under `handlers`:

```php
'handlers' => [
    'maintenance' => App\Modules\Maintenance\MaintenanceModule::class,
],
```

Hooks run inside the lifecycle transaction. Throwing from a hook rolls the
whole transition back. Hooks prepare the module's **own** data only. They must
not write Core tables directly (Section 60): go through Core services or events.

### Gating module routes

```php
Route::prefix('v1')->middleware(['tenant'])->group(function () {
    Route::middleware('module:maintenance')->group(function () {
        Route::apiResource('maintenance-orders', MaintenanceOrderController::class);
    });
});
```

The request gets a 403 unless the module is enabled for the project in
`X-Project-Id`.

## Permissions

| Action | Permission |
|---|---|
| List/show project modules | `module.view` |
| Install, upgrade | `module.install` |
| Configure | `module.configure` |
| Enable | `module.enable` |
| Disable | `module.disable` |
| Uninstall | `module.uninstall` |

Organization Owner and Module Manager hold all of them. Project Admin can view,
configure, enable and disable, but cannot install, upgrade or uninstall.

Permissions a module declares (for example `maintenance.view`) are created
when its version is registered. They are **not** granted to any role
automatically; assigning them stays an explicit decision.

The catalog (`GET /api/v1/modules`) is platform data. Any authenticated user can
browse it.

## Adding a New Module

1. Add a manifest to `config/modules.php` (or register it from a package).
2. Optionally implement `ModuleContract` (extend `BaseModule`) and register it
   under `handlers`.
3. Put its code under `app/Modules/{Name}/`, with routes behind
   `module:{slug}`.
4. Run `php artisan modules:sync`.

Core code does not change.

## Future

- Module Builder (Section 18) registers custom modules through the same
  registry and lifecycle.
- Template Engine (Phase 7) installs a template's modules through
  `ModuleService::install()` and `enable()`.
- Audit Log and Webhooks (Phases 12–13) subscribe to `ModuleLifecycleChanged`.
