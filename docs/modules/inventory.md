# Inventory module

Stock levels of assets per location, minimum levels with low-stock alerts,
and stock counts (cycle counts). Code lives in
`backend/app/Modules/Inventory` with its own `inventory_levels`,
`inventory_counts` and `inventory_count_items` tables.

## Stock is derived, not stored

Every asset is tracked individually, so stock is simply the number of
(non-deleted) assets whose current location — Core's `asset_locations`, kept
by `MovementService` — is the location. There is no separate stock figure that
can drift from reality: a delivery, a GPS fix or an RFID read that moves an
asset changes stock immediately.

## Enabling

Install and enable it per project (Modules page, or
`POST /api/v1/project-modules` then `/enable`). The Warehouse Asset Tracker
template installs it. Until it is enabled, `/api/v1/inventory/*` answers
`403` and the web console hides the page.

## Settings

| Key | Type | Default | Effect |
|---|---|---|---|
| `low_stock_threshold` | integer | 0 (off) | Minimum for every location × asset type that has no minimum of its own |

## Minimum levels and low stock

A minimum applies to a location for one asset type, or for all types
together (`asset_type` empty). A group is **low** when it has fewer assets
than its minimum. Groups with a minimum but no assets show with quantity 0.

When an asset leaves a location, the module checks that location (it
listens to Core's `asset.location.updated`). If the move took it below a
minimum, it publishes **`inventory.low_stock`** — once, when the minimum is
crossed, not on every further move while it stays low.

## Stock counts

1. **Open** a count for a location (`POST /inventory/counts`). The assets
   recorded there become *expected*. One open count per location.
2. **Scan** assets (`POST /inventory/counts/{id}/scan`) by system ID or
   serial number — typed, or from a barcode/RFID scanner. Scanning twice is
   harmless. Codes that match no asset of the project are returned as
   `unknown` instead of failing the request.
3. **Complete** (`POST /inventory/counts/{id}/complete`). Each asset ends as:
   - **found**: expected and scanned;
   - **missing**: expected, not scanned. It is only reported; its location
     is not changed (nobody knows where it is);
   - **unexpected**: scanned, but recorded elsewhere. With
     `reconcile: true` it is moved to the counted location through
     `MovementService` (source `inventory`).

## Permissions

| Permission | Allows | Granted by default to |
|---|---|---|
| `inventory.view` | stock, minimums, counts | organization-owner, project-admin, manager, operator, viewer |
| `inventory.adjust` | set minimums; open, scan, complete and cancel counts | organization-owner, project-admin, manager, operator |

## Events

| Event | Payload |
|---|---|
| `inventory.low_stock` | `location_id`, `location_name`, `asset_type` (null = all types), `quantity`, `min_quantity`, `last_asset` |
| `inventory.count.completed` | `inventory_count_id`, `location_id`, `summary` (`expected`, `found`, `missing`, `unexpected`, `reconciled`), `missing` and `unexpected` (system IDs) |

Plus the standard `event`, `timestamp`, `organization_id`, `project_id`.

## API

See [api-v1.md — Inventory](../api/api-v1.md#inventory-module).
