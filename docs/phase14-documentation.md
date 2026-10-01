# Implementation Documentation and Validation Record

## Scope

This document records the first end-to-end vertical slice: organization and
project context, authenticated API access, assets, locations, movements,
module lifecycle, templates, integrations, RFID, GPS, webhooks, and audit
logging. The platform remains generic: customer-specific rules belong in a
project, template, module, integration, or configuration.

## Frontend Runtime Slice

The React application supplies a small, tenant-aware operational UI:

- `/` - project dashboard with asset totals and recent assets.
- `/assets` - asset list, including immutable system ID and customer serial
  number.
- `/locations` - project locations.
- `/movements` - movement history for a supplied asset system ID.
- unauthenticated users are sent to the sign-in screen.

`frontend/src/core/api/client.ts` is the only API transport used by these
pages. It sends the Sanctum bearer token and, when selected, the
`X-Organization-Id` and `X-Project-Id` headers. Never duplicate this logic in
individual pages, and never treat a response ID as authority to access a
tenant resource.

Set `VITE_API_BASE_URL` to the backend API prefix when the frontend is served
separately. It defaults to `/api`, which is appropriate when Nginx proxies the
frontend and backend together.

## Audit Authorization

Audit activity, security, and event-log policies evaluate `audit.view` using
the tenant context resolved by `TenantMiddleware`. This is important because
roles may be organization- or project-scoped. The policies must not use a
generic Gate ability for a permission that has no Gate definition.

## Verification Commands

Run from the repository root:

```powershell
Set-Location frontend
npm run build
npm run lint

Set-Location ..\backend
php artisan test --colors=never
```

The full backend suite may exceed a short command timeout. It can safely be
run in focused groups with `--filter`; the test database is
`asset_tracker_test` as configured by `phpunit.xml`.

## Phase 13 Results (2026-10-01)

- Frontend production build: passed.
- Frontend lint: passed.
- Backend unit and feature test classes: passed in focused runs.
- A failing audit test setup was corrected by explicitly seeding the canonical
  role/permission catalog and assigning roles in the test organization scope.
- Audit policies were corrected to use the validated request tenant context.

## Follow-ups

- PHP 8.5 reports `PDO::MYSQL_ATTR_SSL_CA` deprecation notices during tests.
  Update the database driver/configuration before upgrading the runtime.
- `php vendor/bin/pint --test` currently reports formatting drift throughout
  the pre-existing backend. Address this as a dedicated formatting-only change
  to avoid mixing a repository-wide rewrite with functional work.
- The dashboard is deliberately a first vertical slice. Create/edit/delete,
  filters, custom-field forms, map widgets, and module-specific interfaces
  should be added as separately permission-scoped UI work.
