# Delivery module

Send assets to a customer's site and track their return. Code lives in
`backend/app/Modules/Delivery` with its own `deliveries` and
`delivery_items` tables. It depends on the Customer module (`customer ^1.0`).

## Enabling

Enable **Customer** first, then **Delivery** (Modules page, or
`POST /api/v1/project-modules` then `/enable`). The Industrial Asset Tracker
template installs both. Until Delivery is enabled, `/api/v1/deliveries`
answers `403` and the web console hides the page.

## Settings

| Key | Type | Default | Effect |
|---|---|---|---|
| `require_proof_of_delivery` | boolean | false | `received_by` is required to mark a delivery delivered |

## Lifecycle

```
pending ──dispatch──► in_transit ──deliver──► delivered ──return (all items)──► returned
   │                      │
   └──────cancel──────────┘──► cancelled
```

| Step | What happens to the assets |
|---|---|
| create | One customer, one or more assets. Destination defaults to the customer's site. An asset can be in only one open delivery (`409` otherwise) |
| dispatch | Optional `via_location_id` (e.g. a truck location): the assets move there |
| deliver | Every asset moves to the destination. The destination must be set (`422` otherwise) |
| return | All assets still at the customer, or the `asset_ids` given, move to `to_location_id`. When none are left, the delivery becomes `returned` |
| cancel | Only before delivery. The assets become available again but stay where they are |

Details (`reference`, destination, schedule, notes) can be edited while the
delivery is `pending`.

## Core boundary

The module never writes Core tables. Each asset move goes through
`MovementService` with source `delivery` and metadata `delivery_id`,
`delivery_reference`, `customer_id` and `step` (`dispatched`, `delivered`,
`returned`). Movement history, the asset's current location, the
`asset.location.updated` event and its webhooks work exactly as for a manual
or GPS move.

## Permissions

| Permission | Allows | Granted by default to |
|---|---|---|
| `delivery.view` | list and read | organization-owner, project-admin, manager, operator, viewer |
| `delivery.create` | create | organization-owner, project-admin, manager |
| `delivery.update` | edit, dispatch, deliver, return, cancel | organization-owner, project-admin, manager, operator |

## Events

`delivery.created`, `delivery.dispatched`, `delivery.delivered`,
`delivery.returned`, `delivery.cancelled`. Payload: `delivery_id`,
`reference`, `status`, `customer_id`, `customer_code`,
`destination_location_id`, `assets` (system ID, serial number and item
status of each asset), plus the standard `event`, `timestamp`,
`organization_id`, `project_id`. `delivery.returned` adds `returned_assets`
and `to_location_id`. Each step is also in the activity log.

## API

See [api-v1.md — Deliveries](../api/api-v1.md#deliveries-module).
