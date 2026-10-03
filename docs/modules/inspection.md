# Inspection module

Checklist-based inspections of assets. Code lives in
`backend/app/Modules/Inspection` with its own `inspection_checklists` and
`inspections` tables. It reads Core assets and never writes Core tables.

## Enabling

Install and enable it per project (Modules page, or
`POST /api/v1/project-modules` then `/enable`). The Warehouse and Equipment
Tracker templates install it. Until it is enabled, `/api/v1/inspections`
answers `403` and the web console hides the page.

## Settings

| Key | Type | Default | Effect |
|---|---|---|---|
| `checklist_required` | boolean | true | An inspection needs a checklist; without one for the asset's type, scheduling is refused (`422`) |
| `default_interval_days` | integer | 90 | `next_due_at` = time performed + this many days |

## Checklists

A checklist is a reusable list of checks for the project:

```json
{
  "name": "Forklift daily check",
  "asset_type": "forklift",
  "items": [
    { "key": "brakes", "label": "Brakes", "type": "pass_fail", "required": true },
    { "key": "hour_meter", "label": "Hour meter", "type": "number", "required": false },
    { "key": "remarks", "label": "Remarks", "type": "text" }
  ]
}
```

- `type`: `pass_fail` (answer `true`/`false`), `number`, or `text`.
- `asset_type` limits the checklist to assets of that type; empty means any.
- Checklists are deactivated, not deleted, so past inspections keep pointing
  at them.

## Inspections

```
scheduled ──record──► completed (result: pass | fail)
    └──────cancel────► cancelled
```

- **Schedule** (`POST /inspections`): without `checklist_id`, the project's
  active checklist for the asset's type is used (a type-specific one before
  a generic one). Without `scheduled_at`, it is due at the asset's last
  `next_due_at`, or now.
- **Record** (`POST /inspections/{id}/record`): answers are checked against
  the checklist. A missing required check, a wrong answer type, or an
  unknown key returns `422`. The result is `fail` when any pass/fail check
  fails, otherwise `pass`. Without a checklist, `result` is given directly.
- **On the spot**: `POST /inspections` with `answers` (or `result`)
  schedules and records in one step.
- The checklist is **snapshotted** onto the inspection when recorded, so
  editing a checklist never rewrites past results.

## Permissions

| Permission | Allows | Granted by default to |
|---|---|---|
| `inspection.view` | read inspections and checklists | organization-owner, project-admin, manager, operator, viewer |
| `inspection.create` | perform inspections: schedule and record results | organization-owner, project-admin, manager, operator |
| `inspection.update` | manage: reschedule or cancel inspections, create and edit checklists | organization-owner, project-admin, manager |

## Events

`inspection.created`, `inspection.completed`, `inspection.cancelled`.
Payload: `inspection_id`, `asset_id`, `system_id`, `serial_number`,
`checklist_id`, `status`, `result`, `failed_checks` (labels of the failed
pass/fail checks), `scheduled_at`, `performed_at`, `next_due_at`, plus the
standard `event`, `timestamp`, `organization_id`, `project_id`.

To act on failures (e.g. open a maintenance job or set the asset's status),
subscribe a webhook to `inspection.completed` and check `result`.

## API

See [api-v1.md — Inspections](../api/api-v1.md#inspections-module).
