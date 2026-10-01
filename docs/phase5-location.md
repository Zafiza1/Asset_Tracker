# Phase 5: Location

## Overview
Phase 5 adds the Location and Movement Core entities (Section 23/25/26 of the master spec): where an asset is, and the history of how it got there. Both are Core, not a module or integration — they sit alongside Asset in the hierarchy and are project-scoped like every other tenant-owned entity.

## Completed Components

### 1. Database Migrations
- **locations table**: generic, project-scoped location (`name`, `type`, `address`, `latitude`, `longitude`, `metadata`). `type` is a free string, not an enum — customers define their own vocabulary (warehouse, factory, customer-site, vehicle, field, ...).
- **asset_movements table**: append-only movement history (`asset_id`, `from_location_id`, `to_location_id`, `source`, `recorded_by`, `metadata`, `occurred_at`). Rows are never updated or deleted; corrections are new movements.
- **asset_locations table**: the current-location pointer for each asset (one row per asset), kept in sync by `MovementService` so "where is asset X right now" never has to scan history.

### 2. Models
- **Location**: `organization()`, `project()`, `currentAssets()`, `movementsFrom()`/`movementsTo()`.
- **Movement** (table `asset_movements`): `asset()`, `fromLocation()`, `toLocation()`, `recordedBy()`.
- **AssetLocation**: the current-location pointer model.
- **Asset**: gained `locationAssignment()` (hasOne AssetLocation) and `movements()` (hasMany Movement, newest first).

### 3. MovementService
Centralizes the two writes that must always happen together when an asset moves: append a `Movement` row, and upsert the asset's `AssetLocation` pointer. `from_location_id` auto-resolves from the asset's current location when the caller doesn't supply one, matching the `asset.location.updated` flow documented in `docs/architecture/events.md`.

### 4. Policies
- **LocationPolicy**: full CRUD, permission-gated (`location.view/create/update/delete`).
- **MovementPolicy**: `viewAny`/`create` only — movements are an append-only log with no update/delete ability, checked against the asset's project.

### 5. API Routes
```
GET    /api/v1/locations
POST   /api/v1/locations
GET    /api/v1/locations/{location}
PUT    /api/v1/locations/{location}
DELETE /api/v1/locations/{location}

GET    /api/v1/assets/{asset}/movements
POST   /api/v1/assets/{asset}/movements
```
`AssetResource` now embeds a lightweight `current_location` (id/name/type) when the relation is loaded.

### 6. Testing
- **LocationTest**: CRUD, permission checks, tenant isolation.
- **MovementTest**: recording a movement updates the asset's current location; a second movement auto-resolves `from_location_id`; permission checks; cross-project location/asset rejection.

## Fix: middleware ordering
`SubstituteBindings` (route-model binding) was running *before* `TenantMiddleware` set the tenant context, so a tenant-scoped route-model lookup (e.g. `Asset` by `system_id`) resolved without the `TenantScope` filter on a request's first hit — a cross-tenant id would bind successfully and only get caught by the controller's explicit policy check (403), instead of a clean 404. Existing tests masked this because `TenantContext` is a request-scoped singleton that happened to carry over correctly-scoped state from an earlier call within the same test method. Fixed in `bootstrap/app.php` via `prependToPriorityList`, placing `TenantMiddleware` immediately before `SubstituteBindings` (and still after auth) in the middleware priority list. Also fixed `AuthServiceProvider::$policies`, whose entries were missing leading `\` and were silently resolving to the wrong (non-existent) class names due to PHP's namespace-relative resolution — harmless only because Laravel's naming-convention policy auto-discovery was covering for it.

## Next Steps
Ready for Phase 6: Module Engine (Module Registry, Installation, Configuration).
