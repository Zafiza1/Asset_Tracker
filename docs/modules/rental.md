# Rental module

Rent assets to customers for a period and take them back. Code lives in
`backend/app/Modules/Rental` with its own `rentals` table. It depends on the
Customer module (`customer ^1.0`).

## Enabling

Enable **Customer** first, then **Rental** (Modules page, or
`POST /api/v1/project-modules` then `/enable`). No shipped template installs
it; add it to a project or to your own template. Until it is enabled,
`/api/v1/rentals` answers `403` and the web console hides the page.

## Settings

| Key | Type | Default | Effect |
|---|---|---|---|
| `default_rental_period_days` | integer | 7 | `due_at` when a rental is created without one |
| `late_fee_enabled` | boolean | false | Compute a late fee on late returns |

## Lifecycle

```
reserved ──checkout──► active ──return──► returned
    └──cancel──► cancelled          (extend: move due_at while reserved or active)
```

- One rental is **one asset for one customer**. An asset can be in only one
  reserved or active rental (`409` otherwise).
- **Checkout** moves the asset to the destination (default: the customer's
  site; `422` when there is none). `POST /rentals` with `checkout: true`
  reserves and hands over in one step.
- **Return** moves the asset to `to_location_id`.
- **Cancel** applies to reservations only; an active rental is returned.
- A rental is **overdue** while active past `due_at` (`overdue=1` filter).

Every move goes through Core's `MovementService` with source `rental` and
metadata `rental_id`, `rental_reference`, `customer_id`, `step`.

## Amounts

Computed on return, for reporting and external billing. The platform itself
does not invoice or take payments (billing is out of the MVP scope).

| Field | Rule |
|---|---|
| `rented_days` | From checkout to return, part days rounded up, at least 1 |
| `days_late` | From `due_at` to return, part days rounded up; 0 when on time |
| `rental_amount` | `daily_rate × rented_days` (null without a daily rate) |
| `late_fee` | `late_fee_per_day` (or `daily_rate`) `× days_late` when `late_fee_enabled`; null otherwise |

## Permissions

| Permission | Allows | Granted by default to |
|---|---|---|
| `rental.view` | read rentals | organization-owner, project-admin, manager, operator, viewer |
| `rental.create` | reserve (with `checkout: true`, also needs `rental.update`) | organization-owner, project-admin, manager |
| `rental.update` | check out, extend, take back, cancel | organization-owner, project-admin, manager, operator |

## Events

`rental.created`, `rental.checked_out`, `rental.extended`,
`rental.returned`, `rental.cancelled`. Payload: `rental_id`, `reference`,
`status`, `customer_id`, `customer_code`, `asset_id`, `system_id`,
`serial_number`, `starts_at`, `due_at`, `checked_out_at`, `returned_at`,
`rented_days`, `days_late`, `rental_amount`, `late_fee`, plus the standard
`event`, `timestamp`, `organization_id`, `project_id`. Subscribe a webhook to
`rental.returned` to send the amounts to a billing system.

## API

See [api-v1.md — Rentals](../api/api-v1.md#rentals-module).
