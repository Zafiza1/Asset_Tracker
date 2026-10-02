# GPS Integration

GPS is an **Integration**. A tracker is a `Device` bound to an asset; Core
never parses provider-specific payloads.

```
GPS Tracker → Provider/Gateway → POST /ingest → GPSService (normalize)
           → event_logs (asset.location.updated)
           → MovementService → asset_movements + asset_locations
           → asset.location.updated → audit log + webhooks
```

## Setup

1. `POST /api/v1/integrations` with `{"type": "gps", ...}`, then
   `POST /api/v1/integrations/{id}/connect`.
2. Register trackers: `POST /api/v1/integrations/{id}/gps/register-tracker`
   (`device_id`, 4–32 chars `A-Z 0-9 - _`).
3. Bind each tracker to an asset: `POST /api/v1/devices/{deviceSystemId}/bind`.
4. Issue a key with scope `integration.ingest` (see [RFID](rfid.md), step 5).

## Sending positions

```http
POST /api/v1/integrations/{id}/ingest
X-Api-Key: atk_...

{"device_id": "GPS-00001", "latitude": -6.2088, "longitude": 106.8456,
 "speed": 42.5, "accuracy": 5, "timestamp": "2026-09-23T10:00:00Z"}
```

Latitude/longitude must be numeric and in range; `0` is valid.

## How a fix becomes a movement

- The fix is matched to an existing project location within ~50 m, or a new
  location of type `outdoor` is created.
- A movement is recorded — through the same `MovementService` used by manual
  moves — when the asset has no location yet, the matched location differs,
  or the asset moved more than 10 m since the last fix. Otherwise only the
  event log and `last_seen_at` are updated.
- Every recorded movement emits `asset.location.updated`.

Read the result through the Core API (`GET /api/v1/assets/{id}` and
`/movements`), or the GPS helpers `gps/asset-location` and
`gps/asset-location-history`.

## Example: vehicle tracker without RFID

Project "Vehicle Tracker" enables modules Asset, Location, Maintenance and
only a GPS integration. Nothing in Core changes; assets simply have GPS
trackers bound instead of tags.
