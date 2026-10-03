# Maintenance module

Schedule and record maintenance work on assets. It is the reference
implementation of a business module: everything lives in
`backend/app/Modules/Maintenance` plus its own table, and Core does not know
it exists.

## Enabling

Install and enable it per project (Modules page, or
`POST /api/v1/project-modules` then `/enable`), or through a template that
lists it. Until it is enabled, `/api/v1/maintenance` answers `403` and the
web console hides the Maintenance page.

## Settings

Set with `PUT /api/v1/project-modules/maintenance/configuration`:

| Key | Type | Default | Effect |
|---|---|---|---|
| `default_interval_days` | integer | 30 | `scheduled_at` when a new record has none; interval for auto-scheduling |
| `auto_schedule` | boolean | false | Completing a record schedules the next one `default_interval_days` later |

## Records

`maintenance_records` holds one job on one asset:

```
scheduled ──► in_progress ──► completed
    │               │
    └───────────────┴──────► cancelled
```

- `completed` is only reached through `POST /maintenance/{id}/complete`,
  which stamps `completed_at` / `completed_by` and emits
  `maintenance.completed`.
- Completed and cancelled records are read-only (`409`).
- A follow-up created by `auto_schedule` points to its predecessor through
  `previous_record_id`.

## Permissions

| Permission | Granted by default to |
|---|---|
| `maintenance.view` | organization-owner, project-admin, manager, operator, viewer |
| `maintenance.create` | organization-owner, project-admin, manager |
| `maintenance.update` | organization-owner, project-admin, manager |
| `maintenance.complete` | organization-owner, project-admin, manager, operator |

Defaults come from `default_role_permissions` in the manifest and are applied
by `php artisan modules:sync` (also part of `db:seed`).

## Events

| Event | When |
|---|---|
| `maintenance.created` | A record is scheduled (including auto-scheduled follow-ups) |
| `maintenance.completed` | A record is completed |

Payload: `maintenance_id`, `asset_id`, `system_id`, `serial_number`, `title`,
`type`, `status`, `scheduled_at`, `completed_at`, plus the standard
`event`, `timestamp`, `organization_id`, `project_id`. Subscribe to them with
a webhook like any platform event. Every change is also written to the
activity log (`maintenance.created`, `maintenance.updated`,
`maintenance.completed`).

## API

See [api-v1.md — Maintenance](../api/api-v1.md#maintenance-module).
