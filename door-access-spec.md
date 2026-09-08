# Door access spec (slim) — attendee-based

## Assumptions locked in

- Single door for now: `door_id = 1` hardcoded in the sync query filter. No door-mapping table yet — add one later when a second door exists.
- All times UTC everywhere: `event` start/end (however stored) are converted to UTC when computing `valid_from`/`valid_until`; API payloads are ISO 8601 with `Z`; device SNTP-syncs to UTC and compares in UTC. No local-timezone handling anywhere in this flow.
- PIN length: 6 digits, numeric.

## Schema changes — `attendee` table

```sql
ALTER TABLE `attendee`
  ADD COLUMN `pin` char(6) DEFAULT NULL,
  ADD COLUMN `pin_status` varchar(20) DEFAULT NULL, -- active | used | revoked | expired
  ADD COLUMN `checked_in_at` datetime DEFAULT NULL,
  ADD COLUMN `checked_in_by_id` int DEFAULT NULL,   -- NULL = self check-in via door PIN; set = reception user
  ADD COLUMN `checked_in_method` varchar(20) DEFAULT NULL, -- door_pin | manual
  ADD KEY `IDX_attendee_checked_in_by` (`checked_in_by_id`),
  ADD CONSTRAINT `FK_attendee_checked_in_by` FOREIGN KEY (`checked_in_by_id`) REFERENCES `user` (`id`) ON DELETE SET NULL;
```

Notes:
- `pin` nullable — only bookings on doors with self-access get one generated. Generate at booking creation or async job.
- `id` (attendee.id) IS the credential_id and booking_id. No separate credential table.
- Session start/end = derive from `event`/`occurrence_date` schedule (event start/end time), not stored on attendee. Confirm where event time lives and use that for `valid_from`/`valid_until` (+grace, see below).
- Manual reception check-in writes the same `checked_in_at`/`checked_in_by_id`/`checked_in_method='manual'` fields — single source for attendance reporting regardless of channel.
- Uniqueness: PIN must be unique among attendees with `pin_status='active'` for the same door at overlapping/near-term windows. If one event maps to one door, scope uniqueness by `event_id`'s door; if multiple doors, add `door_id` resolution via event → venue/door mapping (not detailed here — plug into your existing model).

## PIN lifecycle

- `pin_status` transitions: `active` → `used` (on valid door entry) | `revoked` (booking cancelled) | `expired` (past valid_until + grace, background job or lazy-check).
- Grace: 5 min before event start, 5–10 min after event end. Config constant, not per-row.
- Single use: `checked_in_at IS NOT NULL` (or `pin_status='used'`) blocks reuse regardless of channel (door or manual).

## API — server exposes to door controller

Auth: `Authorization: Bearer <device_api_key>` per physical door, scoped to its own door/event(s) only.

### `GET /v1/doors/{door_id}/credentials`

Returns active + near-future (e.g. next 2h) PIN-bearing attendees for this door.

```json
{
  "server_time": "2026-09-08T14:32:00Z",
  "credentials": [
    {
      "credential_id": 8532,          // attendee.id
      "pin": "483920",
      "valid_from": "2026-09-08T14:55:00Z",
      "valid_until": "2026-09-08T16:05:00Z",
      "status": "active"
    }
  ]
}
```
Support `If-None-Match` / `ETag` → `304` when unchanged.

### `POST /v1/doors/{door_id}/events`

Batched, idempotent on `event_id` (client-generated UUID, not attendee.id).

```json
{
  "events": [
    { "event_id": "uuid", "type": "credential_used", "credential_id": 8532, "timestamp": "..." },
    { "event_id": "uuid", "type": "access_denied", "reason": "expired|not_found|already_used", "timestamp": "..." }
  ]
}
```
On `credential_used`: server sets `checked_in_at=timestamp`, `checked_in_by_id=NULL`, `checked_in_method='door_pin'`, `pin_status='used'`.
Response: `202 { "accepted": ["uuid", ...] }`.

### `POST /v1/doors/{door_id}/heartbeat`

```json
{ "firmware_version": "1.2.0", "uptime_s": 83920, "relay_state": "locked", "last_successful_sync": "...", "cached_credential_count": 2 }
```

## PIN regeneration (door opened but user didn't get through)

Scenario: PIN validated, relay pulsed, `credential_used` fired and `pin_status` set to `used` — but the door didn't actually open in time (user too slow, mechanical miss, etc). Need a fresh PIN for the same attendee/slot.

**Endpoint (web app → server, staff or self-service auth — your call):**

`POST /v1/attendees/{id}/regenerate-pin`

- Validates: attendee's session window hasn't fully expired (allow within grace).
- Sets: new random unique 6-digit `pin`, `pin_status='active'`, clears `checked_in_at`, `checked_in_by_id`, `checked_in_method`.
- Returns the new PIN to display/send to the user.
- `valid_from`/`valid_until` unchanged — still the same booking window (+ grace).

**Why this works with the door controller without extra device logic:**

The device's credential sync (§ `GET /v1/doors/{door_id}/credentials`) must be treated as an **authoritative full replace** of the local cache on every successful poll — not an incremental merge/append. So:

- Regeneration changes `pin` and flips `pin_status` back to `active` on the attendee row.
- Next poll (≤ 20–30s later), the device overwrites its local record for that `credential_id` with the new pin/status — the old (already-consumed) pin stops working, the new one starts working, no special-case code needed on the firmware side.
- Worst case latency for the new PIN to reach the door is one poll interval. If that's too slow in practice, drop the poll interval to ~10–15s, or add the Phase 2 MQTT `resync` push command to force an immediate pull after regeneration — don't build that unless you need it.

Firmware requirement this implies (add to §Firmware): on each sync response, **replace** the cached record for each `credential_id` wholesale (pin, valid_from, valid_until, status) rather than only inserting unseen ones. Treat entries missing from the response as no longer relevant to this door (expired/cancelled) and drop them from cache.

## Manual (reception) check-in

Same underlying mutation as `credential_used`, different entry point — plain internal endpoint, no device auth:

`POST /v1/attendees/{id}/check-in` (authenticated as staff user)
→ sets `checked_in_at=now()`, `checked_in_by_id=<staff user id>`, `checked_in_method='manual'`, `pin_status='used'` if a pin existed.

Reject if already checked in (idempotent 409 or return existing check-in, your call).

## Firmware (ESP32-S3-ETH)

- Boot: Ethernet up, SNTP sync, full credential pull.
- Poll `/credentials` every 20–30s; cache locally; keep operating from last-good cache on failure.
- Keypad match: check PIN + `now` within `[valid_from, valid_until]` + not already used locally → pulse relay, mark used locally immediately (prevents double-use before next sync), queue `credential_used`.
- No match / expired / used → queue `access_denied`, don't leak which reason to the keypad UI beyond generic "denied".
- Lockout: 5 consecutive fails → 30–60s cooldown, emit `lockout_triggered` (send as `access_denied` variant or separate type, your choice).
- Event queue: ring buffer, retry with backoff until flushed.
- Relay: momentary pulse (3–5s), not held open for session duration.

## Non-negotiable physical check

Fail-safe vs fail-secure catch wiring — confirm against local fire code if this door is an egress path. Not an API concern but blocks the hardware build.

## Open items

- Where event start/end time actually lives (confirm before computing valid_from/valid_until).
- Door-to-event mapping if venue has >1 door.
- PIN length (default 6 digits, numeric).
