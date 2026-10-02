# RFID Integration

RFID is an **Integration**, not part of the Asset Core. Assets have no RFID
column; a tag is a `Device` linked to an asset through a `DeviceBinding`.

```
RFID Tag → RFID Reader → Gateway → POST /ingest → RFIDService (normalize)
        → event_logs (asset.detected) → binding → Asset → webhooks
```

## Setup

1. Create the integration (`integration.connect`):
   `POST /api/v1/integrations` with `{"name": "Warehouse RFID", "type": "rfid", "config": {...}}`.
   Config keys named like secrets (`api_key`, `token`, `password`, `*secret*`)
   are encrypted at rest and returned masked (`********`).
2. Connect it: `POST /api/v1/integrations/{id}/connect`.
3. Register readers and tags:
   `POST /api/v1/integrations/{id}/rfid/register-reader` (`reader_id`),
   `POST /api/v1/integrations/{id}/rfid/register-tag` (`tag_id`, 8–24 hex chars).
4. Bind each tag to an asset:
   `POST /api/v1/devices/{deviceSystemId}/bind` with `{"asset_id": 123}`.
5. Issue a gateway key: `POST /api/v1/api-keys` with
   `{"name": "Gateway A", "scopes": ["integration.ingest"], "integration_id": <id>}`.
   Store the returned `key` on the gateway — it is shown once.

## Sending reads

```http
POST /api/v1/integrations/{id}/ingest
X-Api-Key: atk_...
Content-Type: application/json

{"readings": [
  {"tag_id": "E2000017221101441890ABCD", "reader_id": "READER-01", "rssi": -52, "antenna": 2}
]}
```

Up to 500 readings per request. Each accepted read produces an
`asset.detected` event log. If the tag is bound, the asset's `last_seen_at`
is updated and `asset.detected` is sent to subscribed webhooks. Reads of
unknown or unbound tags are still stored (with a null `asset_id`) so they can
be investigated, but do not change any asset.

The older endpoints `rfid/ingest-read` and `rfid/bulk-ingest-reads` (user
token only) remain available for backward compatibility.

## Replacing a damaged tag

```
POST /api/v1/devices/{oldTag}/unbind
POST /api/v1/devices/{newTag}/bind   {"asset_id": 123}
```

or, in one step on the new tag when it was bound elsewhere:
`{"asset_id": 123, "replace": true, "reason": "tag damaged"}`. The asset's
system ID never changes; binding history is kept.

## Failure behaviour

A failing reader or gateway never affects Core: readings are rejected
per-item, and a failing health check moves the integration to `degraded`
(emitting `integration.degraded`) without touching asset data.
