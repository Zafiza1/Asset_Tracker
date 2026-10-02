# API v1 Reference

Base URL: `/api`. Every endpoint except `/health`, `/auth/login` and
`/auth/register` requires `Authorization: Bearer <token>` (Laravel Sanctum).

## Conventions

### Response envelope

```json
{ "success": true, "data": {}, "message": "..." }
```

```json
{ "success": false, "message": "Validation failed", "errors": { "field": ["..."] } }
```

| Status | Meaning |
|---|---|
| 401 | Missing or invalid token |
| 403 | Authenticated but not allowed (missing permission, not a member, role above your own) |
| 404 | Not found, or not visible in your tenant context |
| 409 | Request conflicts with current state (already installed, last owner, …) |
| 422 | Validation failed, or a required context is missing |
| 429 | Rate limit exceeded (too many requests) |

### Rate Limiting

All API endpoints are rate-limited to prevent abuse and ensure fair usage:

| Rate Limiter | Limit | Scope | Usage |
|---|---|---|---|
| `auth` | 5 requests/minute | IP address | Authentication endpoints (login, register) |
| `api` | 60 requests/minute | User ID (authenticated) or IP (unauthenticated) | General API read operations |
| `api-write` | 30 requests/minute | User ID (authenticated) or IP (unauthenticated) | Write operations (POST, PUT, DELETE) |
| `integration` | 120 requests/minute | API key, else user ID, else IP | Integration and ingestion endpoints (RFID, GPS, `/v1/events`, `/ingest`) |

When a rate limit is exceeded, the API returns HTTP 429 with the following response:

```json
{
  "success": false,
  "message": "Too many attempts. Please try again later."
}
```

Rate limit headers are included in the response:

- `X-RateLimit-Limit`: The maximum number of requests allowed
- `X-RateLimit-Remaining`: The number of requests remaining
- `Retry-After`: Seconds until the limit resets (when rate limited)

### Lists

List endpoints accept `page`, `per_page` (default 25, max 100), `search`,
`sort` (field name; prefix `-` for descending) and endpoint-specific filters.
They return:

```json
{ "success": true, "data": [], "meta": { "current_page": 1, "per_page": 25, "total": 0, "last_page": 1 } }
```

### Tenant context

There are two kinds of endpoint:

- **Control Plane** (organizations, projects, memberships) takes the tenant
  from the URL.
- **Runtime** (assets, locations, movements, project modules) takes it from
  headers:

```
X-Organization-Id: 12
X-Project-Id: 34
```

If you send only `X-Project-Id`, the organization is derived from it. If you
send neither, the user's default context from `/auth/switch-*` is used. The
project must belong to the organization, or the request gets a 403.

### Access model

Roles are granted at organization level (they apply to every project in that
organization) or at project level. See
[tenancy.md](../architecture/tenancy.md#role-scope-and-inheritance).

---

## Auth

| Method | Path | Notes |
|---|---|---|
| GET | `/health` | Public |
| POST | `/auth/register` | `name`, `email`, `password`, `password_confirmation`, `phone?`. Grants no roles. Rate limited: 5/minute |
| POST | `/auth/login` | `email`, `password`, `device_name?` → `token`. Rate limited: 5/minute |
| POST | `/auth/logout` | Revoke the current token. Rate limited: 60/minute |
| POST | `/auth/logout-all` | Revoke all tokens. Rate limited: 60/minute |
| POST | `/auth/refresh` | Replace the current token. Rate limited: 60/minute |
| GET | `/auth/me` | User, memberships, roles and permissions for the current context. Rate limited: 60/minute |
| POST | `/auth/switch-organization` | `organization_id`. Sets the default context. Rate limited: 60/minute |
| POST | `/auth/switch-project` | `project_id`. Sets the default context. Rate limited: 60/minute |

---

## Control Plane

### Organizations

| Method | Path | Permission |
|---|---|---|
| GET | `/v1/organizations` | Lists your organizations (all of them for Platform Admin). Filters: `status`. Sort: `name`, `slug`, `created_at`. Rate limited: 60/minute |
| POST | `/v1/organizations` | Anyone while `PLATFORM_SELF_SERVICE_ORGANIZATIONS=true`; otherwise Platform Admin or `organization.create`. Rate limited: 30/minute |
| GET | `/v1/organizations/{id}` | Member. Rate limited: 60/minute |
| PUT | `/v1/organizations/{id}` | `organization.update`. Rate limited: 30/minute |
| DELETE | `/v1/organizations/{id}` | `organization.delete` (Platform Admin). Also soft-deletes the organization's projects. Rate limited: 30/minute |

Create/update body: `name`, `slug?` (lowercase-dashed; generated from the name
when omitted), `description?`, `email?`, `phone?`, `address?`, `settings?`
(object).

The creator becomes **Organization Owner** and the organization becomes their
default context.

### Organization members

| Method | Path | Permission |
|---|---|---|
| GET | `/v1/organizations/{id}/members` | Member |
| POST | `/v1/organizations/{id}/members` | `organization.manage-users` |
| PUT | `/v1/organizations/{id}/members/{userId}` | `organization.manage-users` |
| DELETE | `/v1/organizations/{id}/members/{userId}` | `organization.manage-users`, or yourself (leave) |

POST body: `email` (a registered user), `role?` (role slug, granted at
organization level). PUT body: `role` (slug, or `null` for a plain member).

Member object:

```json
{ "id": 7, "name": "…", "email": "…", "status": "active",
  "membership": "owner|member", "roles": ["organization-owner"], "joined_at": "…" }
```

Rules:
- You cannot grant a role above your own level, or manage a member above it.
- `platform-admin` cannot be granted here.
- You cannot change your own role.
- An organization always keeps one owner.
- Removal also removes the member from every project of the organization.

### Projects

| Method | Path | Permission |
|---|---|---|
| GET | `/v1/organizations/{id}/projects` | Member. Organization-level roles see all projects; others only their own. Filters: `status` |
| POST | `/v1/organizations/{id}/projects` | `project.create` in that organization |
| GET | `/v1/projects/{id}` | Project access |
| PUT | `/v1/projects/{id}` | `project.update` |
| DELETE | `/v1/projects/{id}` | `project.delete` (soft delete) |

Create body: `name`, `slug?` (unique within the organization), `description?`,
`template_id?` (an available template), `settings?`, `starts_at?`, `ends_at?`.
Update also accepts `status` (`active` | `archived`); `template_id` cannot be
changed.

When an organization member creates a project, they become its **Project Admin**.
The chosen template is recorded on the project. Applying the template's
modules is part of the Template Engine (Phase 7).

### Project members

| Method | Path | Permission |
|---|---|---|
| GET | `/v1/projects/{id}/members` | Project access |
| POST | `/v1/projects/{id}/members` | `project.manage-users` |
| PUT | `/v1/projects/{id}/members/{userId}` | `project.manage-users` |
| DELETE | `/v1/projects/{id}/members/{userId}` | `project.manage-users`, or yourself (leave) |

Same bodies and rules as organization members, plus:
- The user must already belong to the organization.
- `organization-owner` cannot be granted at project level.
- `roles` lists project-level roles only.

---

## Runtime (requires tenant context)

### Assets

| Method | Path | Permission |
|---|---|---|
| GET | `/v1/assets` | `asset.view`. Filters: `status`, `asset_type`. Search: name, serial number, system ID. Sort: `name`, `created_at`, `serial_number`, `status`. Rate limited: 60/minute |
| POST | `/v1/assets` | `asset.create`. Rate limited: 30/minute |
| GET | `/v1/assets/{systemId}` | `asset.view`. Rate limited: 60/minute |
| PUT | `/v1/assets/{systemId}` | `asset.update`. Rate limited: 30/minute |
| DELETE | `/v1/assets/{systemId}` | `asset.delete`. Rate limited: 30/minute |

Body: `name`, `serial_number` (unique within the project), `description?`,
`asset_type?`, `status?`, `metadata?`. `system_id` (`AST-…`) is generated by
the platform and cannot be changed.

`GET /v1/assets/{systemId}` also returns `devices` (active device bindings,
each with the integration it reports through) and `last_seen_at` (the last
time any integration saw the asset).

| Method | Path | Permission |
|---|---|---|
| GET | `/v1/assets/{systemId}/activity` | `asset.view`. Audit entries and integration events for the asset, newest first (max 50) |

Creating, updating, deleting or changing the status of an asset emits
`asset.created`, `asset.updated`, `asset.deleted` and `asset.status.changed`
to the activity log and to subscribed webhooks.

### Locations

| Method | Path | Permission |
|---|---|---|
| GET | `/v1/locations` | `location.view`. Filter: `type` |
| POST | `/v1/locations` | `location.create` |
| GET/PUT/DELETE | `/v1/locations/{id}` | `location.view` / `update` / `delete` |

Body: `name`, `type?`, `address?`, `latitude?`, `longitude?`, `metadata?`.

### Movements

| Method | Path | Permission |
|---|---|---|
| GET | `/v1/assets/{systemId}/movements` | `movement.view` |
| POST | `/v1/assets/{systemId}/movements` | `movement.create` |

Body: `to_location_id`, `from_location_id?` (defaults to the current
location), `source?`, `occurred_at?`, `metadata?`.

### Modules

| Method | Path | Permission |
|---|---|---|
| GET | `/v1/modules` | Any authenticated user (platform catalog). Filter: `category` |
| GET | `/v1/modules/{slug}` | Any authenticated user |
| GET | `/v1/project-modules` | `module.view`. Filter: `status` |
| POST | `/v1/project-modules` | `module.install`. Body: `module`, `version?`, `configuration?` |
| GET | `/v1/project-modules/{slug}` | `module.view` |
| PUT | `/v1/project-modules/{slug}/configuration` | `module.configure`. Body: `configuration` |
| POST | `/v1/project-modules/{slug}/enable` | `module.enable` |
| POST | `/v1/project-modules/{slug}/disable` | `module.disable` |
| POST | `/v1/project-modules/{slug}/upgrade` | `module.install`. Body: `version?`, `configuration?` |
| DELETE | `/v1/project-modules/{slug}` | `module.uninstall` |

See [modules.md](../architecture/modules.md) for the lifecycle rules.

### Devices — binding

`POST /v1/devices/{systemId}/bind` (`device.update` + `asset.update`). Body:
`asset_id`, `replace?` (default `false`), `reason?`.

A device that is already bound to a different asset is **not** moved
silently: the request fails with `409` unless `replace: true` is sent (a
deliberate hardware swap). The old binding is closed and the reason recorded.
The asset must be in the same project as the device. A device has at most one
active binding (also enforced by a database index).

### Dashboard

| Method | Path | Permission |
|---|---|---|
| GET | `/v1/dashboard` | `asset.view` |

Returns `totals` (`assets`, `active`, `tracked`, `offline`), `by_status`,
`by_type`, `by_location`, `integrations` (count per status) and
`recent_activity`. An asset is **offline** when it has an active device binding
but no integration has seen it for `PLATFORM_OFFLINE_AFTER_MINUTES` (default
60); assets without devices are never counted as offline.

### Health

| Method | Path | Auth |
|---|---|---|
| GET | `/health` | Public. Database, Redis and queue status. `503` only when the database is down; Redis/queue problems report `degraded` |
| GET | `/v1/health/integrations` | `integration.view`. Status of each integration in the project |

---

## Integration Endpoints

### RFID

| Method | Path | Notes |
|---|---|---|
| POST | `/v1/integrations/{id}/rfid/register-tag` | Register an RFID tag. Rate limited: 120/minute |
| POST | `/v1/integrations/{id}/rfid/register-reader` | Register an RFID reader. Rate limited: 120/minute |
| POST | `/v1/integrations/{id}/rfid/bulk-register-tags` | Bulk register tags. Rate limited: 120/minute |
| POST | `/v1/integrations/{id}/rfid/ingest-read` | Ingest a tag read event. Rate limited: 120/minute |
| POST | `/v1/integrations/{id}/rfid/bulk-ingest-reads` | Bulk ingest tag reads. Rate limited: 120/minute |
| GET | `/v1/integrations/{id}/rfid/devices` | List RFID devices. Rate limited: 120/minute |
| GET | `/v1/integrations/{id}/rfid/stats` | Get RFID statistics. Rate limited: 120/minute |

### GPS

| Method | Path | Notes |
|---|---|---|
| POST | `/v1/integrations/{id}/gps/register-tracker` | Register a GPS tracker. Rate limited: 120/minute |
| POST | `/v1/integrations/{id}/gps/bulk-register-trackers` | Bulk register trackers. Rate limited: 120/minute |
| POST | `/v1/integrations/{id}/gps/ingest-location` | Ingest a location update. Rate limited: 120/minute |
| POST | `/v1/integrations/{id}/gps/bulk-ingest-locations` | Bulk ingest location updates. Rate limited: 120/minute |
| GET | `/v1/integrations/{id}/gps/devices` | List GPS devices. Rate limited: 120/minute |
| GET | `/v1/integrations/{id}/gps/stats` | Get GPS statistics. Rate limited: 120/minute |
| GET | `/v1/integrations/{id}/gps/asset-location` | Get current asset location. Rate limited: 120/minute |
| GET | `/v1/integrations/{id}/gps/asset-location-history` | Get asset location history. Rate limited: 120/minute |

Integration endpoints have a higher rate limit (120/minute) to accommodate automated systems and high-frequency data ingestion from hardware devices.

---

## Machine Access (gateways, hardware, external systems)

These routes accept **either** an `X-Api-Key` header **or** a user's Bearer
token plus tenant context. With an API key, the key alone defines the
organization and project; `X-Organization-Id`/`X-Project-Id` headers are
ignored and cannot widen it.

### API keys

| Method | Path | Permission |
|---|---|---|
| GET | `/v1/api-keys` | `api-key.view`. Never returns the key itself |
| POST | `/v1/api-keys` | `api-key.manage`. Body: `name`, `scopes` (`event.ingest`, `integration.ingest`), `integration_id?` (restrict to one integration), `expires_at?` |
| DELETE | `/v1/api-keys/{id}` | `api-key.manage`. Revokes the key |

The response to `POST` contains `data.key` (`atk_…`). It is shown **once**;
only its SHA-256 hash is stored.

### Publish a standard event

`POST /v1/events`: API key with scope `event.ingest`, or a user with the
`event.ingest` permission. Returns `202` with the stored event log.

```json
{
  "event": "asset.location.updated",
  "asset_id": "AST-01J8X9ABCD",
  "location_id": 12,
  "timestamp": "2026-09-23T10:00:00Z",
  "source": "erp",
  "metadata": {}
}
```

`asset_id` is the asset's system ID. Instead of it you may send `device_id`
(a device serial number), which is resolved through its active binding.

| Event | Effect in Core |
|---|---|
| `asset.location.updated` | Requires `location_id` in the project. Records a movement and updates the current location |
| `asset.detected` | Updates `last_seen_at`; emits `asset.detected` to webhooks |
| `asset.status.changed` | Requires `metadata.status`. Updates the asset status through the normal model path |
| `asset.created` / `.updated` / `.deleted` | Rejected (`422`); these are emitted by the platform only |
| Anything else matching `a.b[.c…]` (e.g. `maintenance.completed`) | Stored in `event_logs` and forwarded to subscribed webhooks unchanged |

### Push raw readings to an integration

`POST /v1/integrations/{id}/ingest`: API key with scope `integration.ingest`
(and, if the key is restricted, the same integration), or a user with
`integration.configure`.

Send a single reading object, or `{"readings": [ ... ]}` with up to 500. The
integration's type selects the adapter (`config/platform.php` → `ingestors`):
RFID expects `tag_id` (+ `reader_id`, …), GPS expects `device_id`,
`latitude`, `longitude` (+ `speed`, `accuracy`, `timestamp`, …). Malformed
readings are reported per index in `data.errors` and never abort the batch.
Disconnected integrations reject readings with `409`.

See [RFID](../integrations/rfid.md) and [GPS](../integrations/gps.md).

---

## Webhooks

Webhooks allow external systems to receive real-time notifications about events in the platform. Each webhook can subscribe to specific event types and will receive HTTP POST requests when those events occur.

### Webhook Events

The following event types are available for subscription:

- `asset.created` - When a new asset is created
- `asset.updated` - When an asset is updated
- `asset.deleted` - When an asset is deleted
- `asset.location.updated` - When an asset's location changes
- `asset.status.changed` - When an asset's status changes
- `project.module.installed` - When a module is installed to a project
- `project.module.configured` - When a module is configured
- `project.module.enabled` - When a module is enabled
- `project.module.disabled` - When a module is disabled
- `project.module.uninstalled` - When a module is uninstalled
- `project.module.upgraded` - When a module is upgraded
- `asset.detected` - When an integration (RFID, BLE, …) or `POST /v1/events` detects an asset
- `integration.connected` / `integration.disconnected` / `integration.degraded` - When an integration's status changes
- Any custom event published through `POST /v1/events` (e.g. `maintenance.completed`)

Event names are validated by format (`segment.segment[.segment…]`, lowercase),
not against a closed list, so modules and integrations can introduce new ones.

### Webhook Management

|| Method | Path | Permission |
||---|---|---|
|| GET | `/v1/webhooks` | `webhook.view`. Filters: `active`. Rate limited: 60/minute |
|| POST | `/v1/webhooks` | `webhook.create`. Rate limited: 30/minute |
|| GET | `/v1/webhooks/{id}` | `webhook.view`. Rate limited: 60/minute |
|| PUT | `/v1/webhooks/{id}` | `webhook.update`. Rate limited: 30/minute |
|| DELETE | `/v1/webhooks/{id}` | `webhook.delete`. Rate limited: 30/minute |
|| POST | `/v1/webhooks/{id}/test` | `webhook.test`. Rate limited: 30/minute |
|| POST | `/v1/webhooks/{id}/regenerate-secret` | `webhook.update`. Rate limited: 30/minute |
|| POST | `/v1/webhooks/{id}/toggle-active` | `webhook.update`. Rate limited: 30/minute |

Create body: `name`, `endpoint` (valid URL), `secret?` (optional, auto-generated if not provided), `events` (array of event types), `active?` (default: true), `retry_policy?` (object with `max_attempts`, `retry_delay`, `backoff_multiplier`), `metadata?` (object).

Update body: Same as create, all fields optional.

### Webhook Deliveries

|| Method | Path | Permission |
||---|---|---|
|| GET | `/v1/webhooks/{id}/deliveries` | `webhook.view`. Filters: `status`, `event_type`. Rate limited: 60/minute |
|| GET | `/v1/webhooks/{id}/stats` | `webhook.view`. Rate limited: 60/minute |
|| POST | `/v1/webhooks/{id}/retry-deliveries` | `webhook.manage`. Rate limited: 30/minute |

### Webhook Payload Format

All webhook deliveries include the following headers:

- `Content-Type: application/json`
- `X-Webhook-Signature: <signature>` - HMAC-SHA256 signature of the payload (if secret is configured)
- `X-Webhook-Event: <event_type>` - The event type that triggered the webhook
- `X-Webhook-Id: <webhook_id>` - The webhook ID
- `X-Webhook-Delivery-Id: <delivery_id>` - The delivery ID
- `X-Webhook-Timestamp: <iso8601>` - When the webhook was delivered
- `User-Agent: AssetTracker-Webhook/1.0`

Example payload:

```json
{
  "event": "asset.created",
  "timestamp": "2026-10-01T10:00:00Z",
  "organization_id": 1,
  "project_id": 1,
  "asset_id": 1,
  "system_id": "AST-01J8X9",
  "serial_number": "TAB-C2H2-00001",
  "name": "Cylinder C2H2",
  "asset_type": "C2H2",
  "status": "available"
}
```

### Signature Verification

To verify webhook authenticity:

1. Receive the `X-Webhook-Signature` header
2. Compute HMAC-SHA256 of the raw request body using your webhook secret
3. Compare with the received signature using constant-time comparison

Example (PHP):

```php
$signature = $_SERVER['HTTP_X_WEBHOOK_SIGNATURE'];
$payload = file_get_contents('php://input');
$expected = hash_hmac('sha256', $payload, $your_webhook_secret);

if (hash_equals($expected, $signature)) {
    // Signature is valid
}
```

### Retry Policy

Failed webhook deliveries are automatically retried based on the retry policy:

- `max_attempts`: Maximum number of retry attempts (default: 3, max: 10)
- `retry_delay`: Initial delay in seconds before first retry (default: 60, range: 10-3600)
- `backoff_multiplier`: Multiplier for exponential backoff (default: 2, range: 1-5)

Example retry policy:

```json
{
  "max_attempts": 5,
  "retry_delay": 30,
  "backoff_multiplier": 2
}
```

With this policy, retries occur at: 30s, 60s, 120s, 240s (5 attempts total).
