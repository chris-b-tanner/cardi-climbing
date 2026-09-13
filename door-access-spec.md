# Door access spec (slim)

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
- The granular `authorized_at`/`door_open_at`/`door_closed_at` timestamps do **not** live here —
  they moved to the dedicated `access_event` table (§ Access event log below) once it became clear
  a keyholder's key-only entry, and an unauthorized door-open with nobody's PIN involved at all,
  both need somewhere to be recorded that isn't an attendee row. `checked_in_at` stays here as
  attendee's own "attendance complete" summary field — it's set once the matching `access_event`
  reaches `door_closed`, same trigger as before, just sourced from the new table instead of storing
  the raw timestamps directly.
- Manual reception check-in writes the same `checked_in_at`/`checked_in_by_id`/`checked_in_method='manual'` fields — single source for attendance reporting regardless of channel. It creates no `access_event` row (see § Manual check-in) since nothing physical is being observed.
- Uniqueness: PIN must be unique among attendees with `pin_status='active'` for the same door at overlapping/near-term windows, **and** against any value currently held in `user.keyholder_pin` (§ Keyholder disarm PIN) — the two PIN pools must never collide. If one event maps to one door, scope uniqueness by `event_id`'s door; if multiple doors, add `door_id` resolution via event → venue/door mapping (not detailed here — plug into your existing model).

## PIN lifecycle

- `pin_status` transitions: `active` → `used` (on valid door entry) | `revoked` (booking cancelled) | `expired` (past valid_until + grace, background job or lazy-check).
- Grace: 5 min before event start, 5–10 min after event end. Config constant, not per-row.
- Single use: `checked_in_at IS NOT NULL` (or `pin_status='used'`) blocks reuse regardless of channel (door or manual).
- Attendee PIN generation excludes any value currently held in `user.keyholder_pin`, and vice versa
  when a keyholder PIN is (re)assigned — see § Keyholder disarm PIN. The two pools are numerically
  indistinguishable to the device (both are just a 6-digit string), so they must never collide;
  enforcing it at generation/assignment time is cheap given how small the keyholder set is.

## Access event log

The three-stage timestamps (`authorized_at`/`door_open_at`/`door_closed_at`) originally lived
directly on `attendee`. That worked while every door interaction was tied to a booking, but stops
working the moment a keyholder can walk through on a physical key with no booking at all, or the
sensor fires with nobody authorized anything. Splitting those onto `attendee` for the booking case
and into `door_log` for everything else would work, but `door_log` is meant for device
diagnostics (OTA, boot, sync health) — mixing door *activity* into it muddies both. A dedicated
table is the better shape: one place that captures every door interaction, regardless of source,
queryable chronologically per door or per person.

```sql
CREATE TABLE `access_event` (
  `id`                INT AUTO_INCREMENT PRIMARY KEY,
  `door_id`           INT NOT NULL DEFAULT 1,
  `event_id`          CHAR(36) NOT NULL,          -- client-generated UUID, device idempotency key
  `type`              VARCHAR(20) NOT NULL,       -- attendee_access | keyholder_access | access_denied | unexpected_open
  `attendee_id`       INT DEFAULT NULL,           -- set for attendee_access / access_denied
  `keyholder_user_id` INT DEFAULT NULL,           -- set for keyholder_access
  `stage`             VARCHAR(20) DEFAULT NULL,   -- authorized | door_open | door_closed — NULL for access_denied
  `denied_reason`     VARCHAR(20) DEFAULT NULL,   -- expired | not_found | already_used — access_denied only
  `authorized_at`     DATETIME DEFAULT NULL,
  `door_open_at`      DATETIME DEFAULT NULL,
  `door_closed_at`    DATETIME DEFAULT NULL,
  `created_at`        DATETIME NOT NULL,
  `updated_at`        DATETIME NOT NULL,
  UNIQUE KEY `UNQ_access_event_event_id` (`event_id`),
  KEY `IDX_access_event_attendee` (`attendee_id`),
  KEY `IDX_access_event_keyholder` (`keyholder_user_id`),
  CONSTRAINT `FK_access_event_attendee` FOREIGN KEY (`attendee_id`) REFERENCES `attendee` (`id`) ON DELETE SET NULL,
  CONSTRAINT `FK_access_event_keyholder` FOREIGN KEY (`keyholder_user_id`) REFERENCES `user` (`id`) ON DELETE SET NULL
);
```

- One row per `event_id`, **updated in place** as `stage` advances — not appended — same
  upsert-never-regress rule the old attendee-column design already required, now backed by a real
  row instead of being an implicit rule about POST ordering.
- `type=unexpected_open` rows have neither `attendee_id` nor `keyholder_user_id` set, and no
  `authorized_at` — nothing authorized them, they start straight at `door_open_at`.
- `type=access_denied` rows are a single-stage write (`stage` stays `NULL`) — `denied_reason`
  carries what used to just be called `reason`.
- `updated_at` gives a cheap "what's stuck at `door_open`" query for free — see the matching Open
  item below on whether anything should act on that automatically.
- `type=attendee_access` reaching `door_closed` still flips `attendee.checked_in_at` /
  `checked_in_by_id` / `checked_in_method` / `pin_status` exactly as described under § Schema
  changes — `attendee` above — this table is the detailed log behind that summary, not a
  replacement for it.

## Keyholder disarm PIN

Some keyholders can already open the door with a physical key, bypassing the lock entirely — the
keypad has no role in that. This PIN's only job is to tell the device "expect this, don't sound
the unexpected-open alarm" for a short window before the key is used; it never unlocks anything
itself, and the relay is never pulsed for it.

```sql
ALTER TABLE `user`
  ADD COLUMN `keyholder_pin` char(6) DEFAULT NULL,
  ADD UNIQUE KEY `UNQ_user_keyholder_pin` (`keyholder_pin`);
```

- Nullable — most users never get one; set only for staff who hold a physical key.
- Same plaintext-numeric storage as `attendee.pin` — the real defense in both cases is the
  device's 5-consecutive-failure lockout, not secrecy of a 6-digit database value, so there's no
  reason to hash one PIN type and not the other.
- Revoke by setting back to `NULL` — no separate status column, there's no "expiry" concept for a
  standing keyholder credential the way there is for a per-booking attendee PIN.

**Device sync** piggybacks on the existing credential poll rather than a second endpoint/cadence
— extend `GET /v1/doors/{door_id}/credentials`'s response with a sibling array:

```json
{
  "server_time": "...",
  "credentials": [ /* unchanged, as below */ ],
  "keyholders": [
    { "user_id": 12, "pin": "913204" }
  ]
}
```

Same "authoritative full replace" rule as `credentials` — the device wholesale-replaces its
keyholder cache on every successful poll too, not just inserts unseen ones.

**Device-side behaviour** (full detail in `door-access-firmware-spec.md` § Keyholder disarm PIN):
a PIN that doesn't match any cached attendee credential is checked against the keyholder cache; a
match opens a 10-second window during which the door being opened is treated as expected rather
than `unexpected_open`. Produces an `access_event` row of `type=keyholder_access` if the door does
open within that window; nothing is written at all if it doesn't — see the firmware spec for why
that's an acceptable non-event rather than something worth alarming or logging as a failure.

## API — server exposes to door controller

Auth: `Authorization: Bearer <device_api_key>` per physical door, scoped to its own door/event(s) only.

**OTA note:** every response below should also carry a `min_firmware_version` field (integer,
`YYYYMMDDXX`) alongside its existing payload — see `door-access-firmware-spec.md` for the full
OTA design this feeds into. Not broken out as separate fields per-endpoint here since it's the
same value on every response.

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
  ],
  "keyholders": [
    { "user_id": 12, "pin": "913204" }   // see § Keyholder disarm PIN — no valid_from/valid_until,
                                          // a standing credential rather than a per-booking one
  ]
}
```
Support `If-None-Match` / `ETag` → `304` when unchanged.

### `POST /v1/doors/{door_id}/events`

Batched, idempotent on `event_id` (client-generated UUID, not attendee.id or user.id). Each event
maps to one `access_event` row (§ Access event log). `attendee_access`, `keyholder_access`, and
`unexpected_open` all arrive in up to three separate POSTs against the **same** `event_id`, one
per stage, as the door-position sensor observes the physical access happen (see
`door-access-firmware-spec.md` § Door position sensing) — this is an **upsert keyed on
`(event_id, stage)`**, not a plain create: process a stage only if it's later than (or equal to,
for a harmless retry) whatever stage is already recorded for that `event_id`, never earlier. A
delayed/reordered retry of an old stage arriving after a newer one has already been applied is a
no-op, not a regression.

```json
{
  "events": [
    { "event_id": "uuid", "type": "attendee_access",  "credential_id": 8532, "stage": "authorized",   "authorized_at": "..." },
    { "event_id": "uuid", "type": "attendee_access",  "credential_id": 8532, "stage": "door_open",    "authorized_at": "...", "door_open_at": "..." },
    { "event_id": "uuid", "type": "attendee_access",  "credential_id": 8532, "stage": "door_closed",  "authorized_at": "...", "door_open_at": "...", "door_closed_at": "..." },
    { "event_id": "uuid", "type": "keyholder_access", "user_id": 12,        "stage": "authorized",   "authorized_at": "..." },
    { "event_id": "uuid", "type": "keyholder_access", "user_id": 12,        "stage": "door_open",    "authorized_at": "...", "door_open_at": "..." },
    { "event_id": "uuid", "type": "keyholder_access", "user_id": 12,        "stage": "door_closed",  "authorized_at": "...", "door_open_at": "...", "door_closed_at": "..." },
    { "event_id": "uuid", "type": "unexpected_open",                        "stage": "door_open",    "door_open_at": "..." },
    { "event_id": "uuid", "type": "unexpected_open",                        "stage": "door_closed",  "door_open_at": "...", "door_closed_at": "..." },
    { "event_id": "uuid", "type": "access_denied", "credential_id": 8532, "reason": "expired|not_found|already_used", "timestamp": "..." }
  ]
}
```
- Every event creates or updates one `access_event` row keyed on `event_id`, setting `type` and
  whichever of `attendee_id` (from `credential_id`) / `keyholder_user_id` (from `user_id`) applies.
- `stage=authorized` (`attendee_access`/`keyholder_access` only): server sets `authorized_at`. For
  `attendee_access` specifically, also sets `pin_status='used'` on the attendee row — same as
  before, reuse-prevention doesn't wait on the physical outcome. Not yet "checked in."
- `stage=door_open`: server sets `door_open_at` on the `access_event` row. Still not "checked in"
  for `attendee_access` — this stage exists for timing/tailgating visibility, not attendance.
  `unexpected_open` has no `authorized` stage at all; it starts here.
- `stage=door_closed`: server sets `door_closed_at`. For `attendee_access` only, this is also the
  point the linked `attendee` row gets `checked_in_at=door_closed_at`, `checked_in_by_id=NULL`,
  `checked_in_method='door_pin'` — `keyholder_access` and `unexpected_open` have no attendee to
  update, the `access_event` row itself is the complete record for those.
- `access_denied` is unchanged in shape — no stage, always a single POST, `denied_reason` on the
  `access_event` row.

Response: `202 { "accepted": ["uuid", ...] }` — accepting the batch, not implying every stage in it
was newer than what the server already had; a client doesn't need to distinguish "applied" from
"harmless no-op," both ack the same way.

### `POST /v1/doors/{door_id}/heartbeat`

```json
{ "firmware_version": "1.2.0", "uptime_s": 83920, "relay_state": "locked", "last_successful_sync": "...", "cached_credential_count": 2 }
```

### `POST /v1/doors/{door_id}/logs`

Batched, idempotent on `log_id` (client-generated UUID) — same pattern as `/events` above. Carries
operational/diagnostic entries only (firmware updates, failed PIN attempts, boot/reset reasons,
sync outages, queue-capacity warnings — see `door-access-firmware-spec.md`'s "Diagnostic log"
section for the full taxonomy). No relation to `attendee`/credential state — unlike `/events`,
receiving a log entry never mutates a booking row, it's purely for remote visibility once the
device has no serial console attached.

```json
{
  "logs": [
    {
      "log_id": "uuid",
      "level": "info|warn|error",
      "category": "boot|ota|auth|sync|hardware|config",
      "reason": "door_propped",
      "message": "short human-readable string",
      "timestamp": "...",
      "context": { "from_version": 2026091301, "to_version": 2026091401 }
    }
  ]
}
```
Response: `202 { "accepted": ["uuid", ...] }`.

Store these somewhere queryable (a `door_log` table is the obvious shape — door, level, category,
`reason`, message, context as JSON, timestamp, received_at). Whether/how they're surfaced in the
admin UI (a list view, filtering by level, an alert on the first `error` in a batch) is a separate,
ordinary product decision — not designed here, just noting the endpoint needs somewhere to land.

**Server-side alerting:** since nobody's watching a beeper at 2am, the point of `door_propped` and
`door_unexpected_open` specifically (both `category=hardware`, `level=error`) is that a human
should hear about them almost as fast as the on-site beeper does. On receiving a batch containing
either `reason`, send an email to `DOOR_ALERT_EMAIL` immediately — don't batch this into a daily
digest with the rest of the log. Match on `reason` (the stable slug), never on `message` text.
Currently a single fixed address rather than a real distribution list — see Open items.

- **Dedupe on `log_id`.** The device retries an unacked log the same way it retries an unacked
  event — a retried POST of the same `log_id` must not send a second email for something already
  alerted on. Track "already alerted" per `log_id`, not per batch received.
- **Rate-limit per door, not per entry.** A stuck-open door or a misbehaving sensor could in
  principle generate repeated distinct `log_id`s for the same ongoing situation faster than anyone
  can usefully act on separate emails — cap to one alert email per door per short cooldown (e.g.
  10 minutes), still recording every entry in `door_log` regardless of whether that particular one
  triggered a send.
- **Deliberately not every `hardware`/`error` reason is email-worthy** — `queue_data_loss`, for
  instance, matters but isn't "something is happening at the door right now" the way the two alarm
  reasons are. Keep the alert-triggering reason list an explicit, short allowlist rather than
  "every error", so this doesn't quietly turn into an inbox for every device hiccup as more
  `hardware` reasons get added later.
- Reuse whatever the app already uses to send transactional email — no new mail infrastructure
  implied by this, just a new trigger into it.

## PIN regeneration (door opened but user didn't get through)

Scenario: PIN validated, relay pulsed, `attendee_access` fired and `pin_status` set to `used` — but the door didn't actually open in time (user too slow, mechanical miss, etc). Need a fresh PIN for the same attendee/slot.

With the door-position sensor (`door-access-firmware-spec.md` § Door position sensing) this is
specifically the "stuck at `authorized`, never reached `door_open`" case — a genuinely fresh
attempt is the right fix there. A door that reached `door_open` and never reached `door_closed` is
a different, physical situation (propped/stuck open, already handled by the local beeper alarm and
a diagnostic log entry) — regenerating a PIN doesn't help that one, don't offer this action for it.

**Endpoint (web app → server, staff or self-service auth — your call):**

`POST /v1/attendees/{id}/regenerate-pin`

- Validates: attendee's session window hasn't fully expired (allow within grace).
- Sets: new random unique 6-digit `pin`, `pin_status='active'`, clears `checked_in_at`, `checked_in_by_id`, `checked_in_method` on the attendee row. Doesn't touch the old `access_event` row — it stays as history of the stuck attempt; the new attempt gets its own `event_id`.
- Returns the new PIN to display/send to the user.
- `valid_from`/`valid_until` unchanged — still the same booking window (+ grace).

**Why this works with the door controller without extra device logic:**

The device's credential sync (§ `GET /v1/doors/{door_id}/credentials`) must be treated as an **authoritative full replace** of the local cache on every successful poll — not an incremental merge/append. So:

- Regeneration changes `pin` and flips `pin_status` back to `active` on the attendee row.
- Next poll (≤ 20–30s later), the device overwrites its local record for that `credential_id` with the new pin/status — the old (already-consumed) pin stops working, the new one starts working, no special-case code needed on the firmware side.
- Worst case latency for the new PIN to reach the door is one poll interval. If that's too slow in practice, drop the poll interval to ~10–15s, or add the Phase 2 MQTT `resync` push command to force an immediate pull after regeneration — don't build that unless you need it.

Firmware requirement this implies (add to §Firmware): on each sync response, **replace** the cached record for each `credential_id` wholesale (pin, valid_from, valid_until, status) rather than only inserting unseen ones. Treat entries missing from the response as no longer relevant to this door (expired/cancelled) and drop them from cache.

## Manual (reception) check-in

Same underlying attendee mutation as `attendee_access`, different entry point — plain internal endpoint, no device auth:

`POST /v1/attendees/{id}/check-in` (authenticated as staff user)
→ sets `checked_in_at=now()`, `checked_in_by_id=<staff user id>`, `checked_in_method='manual'`, `pin_status='used'` if a pin existed.

No `access_event` row is created for this — there's no door hardware involved to generate one
(no `event_id`, no door sensor observation), just a staff member confirming attendance directly
in the admin UI. Don't confuse this with the keyholder disarm PIN (§ Keyholder disarm PIN), which
is a different concept entirely: a code typed on the physical keypad by someone with a key, not a
staff action in a browser.

Reject if already checked in (idempotent 409 or return existing check-in, your call).

## Firmware (ESP32-S3-ETH)

Moved to its own file — see `door-access-firmware-spec.md` for the full device-side brief
(PlatformIO/Arduino project structure, credential sync/keypad/relay logic, and the OTA design for
shipping firmware + SPIFFS updates over the same API). This file stays the server-side source of
truth (schema, PIN lifecycle, API contract); the firmware doc restates the API-facing bits it
depends on so it can be handed to a build agent on its own.

## Non-negotiable physical check

Fail-safe vs fail-secure catch wiring — confirm against local fire code if this door is an egress path. Not an API concern but blocks the hardware build.

## Open items

- Where event start/end time actually lives (confirm before computing valid_from/valid_until).
- Door-to-event mapping if venue has >1 door.
- PIN length (default 6 digits, numeric).
- `door_log` table/entity for `POST /v1/doors/{door_id}/logs` doesn't exist yet — needs a
  migration when this is actually built, same as everything else in this spec.
- No server-side decision yet on what (if anything) surfaces `door_open_at`→`door_closed_at`
  duration to staff — the firmware's local alarm/beeper fires regardless of whether the server
  ever sees it, but if there's a desire to flag repeatedly-slow or repeatedly-propped access on a
  given attendee/door from the admin side, that's a reporting feature to design separately, not
  assumed here.
- No automatic server-side handling of an event stuck at `door_open` with no `door_closed` ever
  arriving (see the matching open item in `door-access-firmware-spec.md`) — `access_event.updated_at`
  makes finding these cheap, but nothing currently queries for or flags them.
- Alert-email recipient is a single fixed address (`DOOR_ALERT_EMAIL` env var, currently
  chris@lend-engine.com) — shipped this way deliberately for now rather than building a real
  distribution list/admin UI ahead of need. Revisit once there's more than one person who needs
  to see `door_propped`/`door_unexpected_open` alerts.
- Whether the alert should ever escalate beyond email (SMS, push, a phone call service) if nobody
  acts on it within some window is a reasonable future ask, explicitly not designed here — email
  only for v1.
- Who assigns/manages `user.keyholder_pin` and how (an admin form field, generated vs.
  staff-chosen, any rotation policy) isn't designed — this spec only covers the column and its
  device-facing behaviour, not the admin UX for setting it.
- Whether a keyholder's `access_event` history should be surfaced anywhere in the admin UI (e.g.
  on their user profile) is a reporting decision, not addressed here.
