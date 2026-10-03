# Modules

Lifecycle, versioning and dependency rules are in
[architecture/modules.md](../architecture/modules.md). This page covers how a
module plugs into the running platform.

## Catalog

The platform catalog lives in `backend/config/modules.php` and is published
to the `modules` / `module_versions` tables with `php artisan modules:sync`.

| Module | Kind |
|---|---|
| asset, location, movement | Core (always enabled) |
| maintenance, inspection, customer, delivery, inventory, rental | Installable per project |

Every catalog entry provides the lifecycle (install / configure / enable /
disable / upgrade / uninstall), versioning and configuration schema.
**Maintenance** ([maintenance.md](maintenance.md)), **Customer**
([customer.md](customer.md)), **Delivery** ([delivery.md](delivery.md)) and
**Inspection** ([inspection.md](inspection.md)) also ship their business
features; Inventory and Rental are catalog entries waiting for theirs. Shared helpers: `App\Modules\ModulePolicy` (permission check),
`App\Modules\ModuleSettings` (a project's module configuration) and
`App\Modules\Concerns\ChecksProjectLocations`.

## Anatomy of a module

Using `backend/app/Modules/Maintenance` as the template:

| Piece | Where | Purpose |
|---|---|---|
| Manifest | `config/modules.php` `catalog` | versions, dependencies, config schema, permissions, `default_role_permissions` |
| Service provider | `config/modules.php` `providers` | registers routes, policy and event listeners |
| Routes | `Modules/<Name>/routes.php` | mounted under `/api/v1/<slug>` with `auth:sanctum`, `tenant`, `module:<slug>` |
| Migration | `database/migrations` | module-owned tables (`organization_id`, `project_id`, FK to Core rows) |
| Model | `Modules/<Name>/Models` | uses `TenantScoping` like every project resource |
| Service | `Modules/<Name>/<Name>Service.php` | business rules, events, audit |
| Policy | `Modules/<Name>/<Name>…Policy.php` | checks the permissions the manifest declares |
| Events | `Modules/<Name>/Events` | extend `WebhookTriggerable` so webhooks receive them |
| Lifecycle hooks (optional) | `config/modules.php` `handlers` | a `ModuleContract` for install/upgrade work |
| UI | `frontend/src/modules/<slug>` | nav entry with `module: '<slug>'` shows only when enabled |

`default_role_permissions` grants the module's permissions to the system
roles when the catalog is synced (`php artisan modules:sync`). It only adds
grants; it never removes one. `GET /api/auth/me` lists the project's enabled
modules in `project_modules`, which the web console uses to show module
pages.

## Rules for module code

- Never write Core tables directly. Use Core services (`MovementService`,
  the `Asset` model so its events fire) or publish a standard event through
  `EventIngestionService` / `POST /api/v1/events`.
- Publish your own events with the standard contract, e.g.
  `maintenance.created`, `maintenance.completed`. They are stored in
  `event_logs` and can be subscribed to by webhooks without platform changes.
- Gate routes with the `module:<slug>` middleware so a project without the
  module enabled gets `403`.
- Store project-specific settings in the module configuration
  (`PUT /api/v1/project-modules/{slug}/configuration`), validated by the
  version's schema. Store per-asset data as custom fields, not new Core
  columns.

## Customer-specific features

A request such as "SIG delivery" becomes a project-level module or
configuration (template + modules + custom fields), never a Core change.
