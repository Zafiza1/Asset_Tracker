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

Catalog entries currently provide the lifecycle (install / configure /
enable / disable / upgrade / uninstall), versioning and configuration
schema. Their business features (for example maintenance work orders) are
not built yet; they are the next step after the Core MVP.

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
