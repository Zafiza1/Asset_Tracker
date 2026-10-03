# Customer module

Customers and other parties that hold or receive assets. Code lives in
`backend/app/Modules/Customer` with its own `customers` table; Core does not
know the module exists. The Delivery module depends on it.

## Enabling

Install and enable it per project (Modules page, or
`POST /api/v1/project-modules` then `/enable`), or through a template that
lists it (Industrial Asset Tracker does). Until it is enabled,
`/api/v1/customers` answers `403` and the web console hides the page.

## Customers

| Field | Notes |
|---|---|
| `code` | Customer-defined reference (e.g. the ERP code). Unique within the project; a deleted customer's code can be reused |
| `name`, `contact_name`, `email`, `phone`, `address` | Contact details |
| `location_id` | The customer's site: a location of the same project (typically type `customer_site`). Deliveries move assets there |
| `status` | `active` or `inactive` |
| `metadata` | Free-form JSON for project-specific data |

The module only references the site location; it never creates or changes
Core locations. Create the site under Locations first, then link it.

Deleting is a soft delete, so history that refers to the customer keeps
resolving.

## Permissions

| Permission | Granted by default to |
|---|---|
| `customer.view` | organization-owner, project-admin, manager, operator, viewer |
| `customer.create` | organization-owner, project-admin, manager |
| `customer.update` | organization-owner, project-admin, manager |
| `customer.delete` | organization-owner, project-admin |

## Events

`customer.created`, `customer.updated`, `customer.deleted`. Payload:
`customer_id`, `code`, `name`, `status`, `location_id`, plus the standard
`event`, `timestamp`, `organization_id`, `project_id`. Each change is also in
the activity log under the same action name.

## API

See [api-v1.md — Customers](../api/api-v1.md#customers-module).
