# Door access spec

Server-side source of truth for the self-access door: data model, behaviour and the API the door controller uses. The firmware spec lives in the PlatformIO project at `/Documents/PlatformIO/Projects/y-wal/spec.md` (referred to below as the firmware spec). Card registration and the card station are covered in `card-setup.md`.

## Ground rules

- **One door.** `door_id = 1` (`DoorAccessService::SUPPORTED_DOOR_ID`). Any other door id gets a 404 on credentials and events, and empty lists from the service. There's no door-mapping table.
- **UTC everywhere.** Session times are converted from UK wall-clock time to UTC (`Event::combineDateAndTime()`, which handles daylight saving). API timestamps are ISO 8601 with `Z`.
- **Entry is by card only.** The keypad is used only for keyholder disarm PINs. Attendees have no PINs.

## How people get through the door

| Who | How | Opens the door? | Recorded as |
|---|---|---|---|
| Member with a confirmed booking on a self-access event | Taps card at the entry reader during the session window | Yes | `attendee_access` |
| Card with all-hours access | Taps card at the entry reader, any time | Yes | `standing_access` |
| Keyholder using a physical key | Types disarm PIN, then uses the key within 10 s | No (the key does it). The PIN only stops the unexpected-open alarm. | `keyholder_access` (only if the door opens) |
| Anyone inside with a recently used card | Taps the internal exit reader | Yes, unconditionally | `member_exit` |
| Anyone inside | Push-to-exit button | Yes | Handled by the firmware (see the firmware spec) |
| Unrecognised or out-of-window card at entry | Taps entry reader | No | `access_denied` |
| Door opens with nothing authorising it | — | — | `unexpected_open`, plus an alert email |

## Session window

A booking's card works from **5 minutes before** session start until **10 minutes after** session end (`GRACE_BEFORE` and `GRACE_AFTER` in `DoorAccessService`). The session times come from the event's `timeFrom`/`timeTo` on the booking's occurrence date.

- The credential is **reusable** for the whole window: stepping out and back in works.
- Credentials are synced to the door up to **2 hours** ahead (`SYNC_WINDOW`).

## Data model

### `attendee` (check-in fields)

| Column | Notes |
|---|---|
| `checked_in_at` | First entry only. Set once, never overwritten. |
| `checked_in_by_id` | Staff user for a manual check-in. Null for a door check-in. |
| `checked_in_method` | `door_card` or `manual`. (`door_pin` exists only on historical rows.) |
| `checked_out_at` | Last exit. Moves later on each exit tap. |
| `checked_out_method` | `door_card`. |

`attendee.id` is the `credential_id` sent to the door. There's no separate credential table.

### `user.keyholder_pin`

`char(6)`, nullable, unique. It's set on the member edit form (`/admin/users/{id}/edit`) and removed by clearing it. It's stored in plain text, because the protection is the device's lockout after repeated failures, not secrecy of the value.

### `access_card`

See `card-setup.md`. The fields that matter to the door are `uid` (upper-case hex, no separators), `status` (only `active` cards are synced) and `all_hours_access` (a boolean, toggled at `/admin/settings/cards/{uid}` by `ROLE_ADMIN`; changes are recorded in the member's notes).

### `access_event`

One row per door interaction, keyed on the device's `event_id` and **updated in place** as the stage advances.

| Column | Notes |
|---|---|
| `door_id` | Always 1 |
| `event_id` | Client UUID, unique. This is the idempotency key. |
| `type` | `attendee_access` / `standing_access` / `keyholder_access` / `member_exit` / `access_denied` / `unexpected_open` |
| `attendee_id` | `attendee_access`, `access_denied` (if it resolved), and `member_exit` (the session it closed, if any) |
| `keyholder_user_id` | `keyholder_access` |
| `card_uid`, `card_user_id` | Any card event. The member is looked up from the UID on the server. |
| `stage` | `authorized` → `door_open` → `door_closed`. Null for `access_denied`. |
| `denied_reason` | `access_denied` only, passed through from the device |
| `authorized_at`, `door_open_at`, `door_closed_at` | `access_denied` stores its time in `authorized_at` |
| `created_at`, `updated_at` | |

Rules:
- **Stages only move forward.** A retried or out-of-order POST of an earlier stage does nothing.
- `unexpected_open` has no `authorized` stage; it starts at `door_open`.
- **Concurrent access:** several authorisations can share one physical door cycle. Each gets its own row, and all of them get the same `door_open_at`/`door_closed_at`. That's expected, not a duplicate. Batching, and the relay only pulsing once per cycle, are handled by the firmware (firmware spec § Door position sensing).

Admin views: `/admin/settings/access-log` (`access_event`) and `/admin/settings/door-log` (`door_log`).

### `door_log`

Device diagnostics, keyed on `log_id`: `door_id`, `level` (`info`/`warn`/`error`), `category` (`boot`/`ota`/`auth`/`sync`/`hardware`/`config`), `reason` (a stable slug), `message`, `context` (JSON), `timestamp` (from the device), `received_at`, and `alert_sent_at`. A log entry never changes any booking.

## Check-in and check-out

- **Door check-in:** when an `attendee_access` event reaches `door_closed`, and the attendee isn't already checked in, the server sets `checked_in_at` to `door_closed_at`, `checked_in_by_id` to null and `checked_in_method` to `door_card`. A valid card that is never actually walked through doesn't mark anyone present.
- **Manual check-in:** `POST /v1/attendees/{id}/check-in`, used by `ROLE_TEAM` with a CSRF token. It sets `checked_in_by_id` to the staff member and `checked_in_method` to `manual`, and returns 409 if the attendee is already checked in. No `access_event` is created.
- **Exit check-out:** happens the **first time the server sees a `member_exit` `event_id`**, whichever stage arrives first, at that event's `authorized_at`. It doesn't wait for `door_closed`, so people leaving through a propped door aren't left checked in. Which booking gets checked out (`DoorAccessService::findSessionForExit()`):
  1. A checked-in booking whose session window covers the tap. If it's already checked out, this is a re-entry and the check-out time moves forward.
  2. Otherwise, if the tap falls within another confirmed booking they never checked into: nothing.
  3. Otherwise (they stayed past the end): their most recent check-in within the last **24 h**, if it's still open.

  If no booking matches, the exit is still logged with `card_uid`/`card_user_id`.

## API: door controller → server

Every request carries `Authorization: Bearer <DOOR_API_KEY>`, otherwise it gets a 401. Every JSON response includes `min_firmware_version` (`DOOR_MIN_FIRMWARE_VERSION`, format `YYYYMMDDXX`), which drives firmware updates; see the firmware spec.

### `GET /v1/doors/{door_id}/credentials`

The door **replaces its whole local cache** with this response on every successful poll.

```json
{
  "server_time": "2026-09-08T14:32:00Z",
  "min_firmware_version": 2026091400,
  "credentials": [
    {
      "credential_id": 8532,
      "card_uid": "04A3B2C1",
      "valid_from": "2026-09-08T14:55:00Z",
      "valid_until": "2026-09-08T16:05:00Z",
      "status": "active"
    }
  ],
  "keyholders":     [ { "user_id": 12, "pin": "913204" } ],
  "standing_cards": [ { "card_uid": "04A3B2C1", "user_id": 42 } ],
  "exit_cards":     [ { "user_id": 42, "card_uid": "04A3B2C1" } ]
}
```

| List | Contents |
|---|---|
| `credentials` | One per confirmed booking on an `is_self_access` event whose window overlaps now to now + 2 h. `card_uid` is the member's active card and is **left out** if they don't have one. `status` is always `active`. |
| `keyholders` | Every user with a `keyholder_pin` |
| `standing_cards` | Active cards with `all_hours_access` |
| `exit_cards` | Active cards that have been **tapped at the door in the last year** (any reader, any outcome). Limited so the list fits in the controller's memory. A replacement card is added after its first tap. |

The response has an `ETag`, and a matching `If-None-Match` gets a `304`. A 304 can't carry a body, so `min_firmware_version` is also sent as the `X-Min-Firmware-Version` header on every response.

Matching order at the entry reader: `credentials` (inside its window) → `standing_cards` → denied. `keyholders` is used by the keypad only. At the exit reader, anything not in `exit_cards` is ignored: the door doesn't open and nothing is recorded.

### `POST /v1/doors/{door_id}/events`

A batch of events, safe to resend: each one is processed only once per `event_id`. Staged event types arrive in up to three POSTs per `event_id`, one per stage. The server makes no change to a booking on `authorized` or `door_open`, except for the `member_exit` check-out.

```json
{
  "events": [
    { "event_id": "uuid", "type": "attendee_access",  "credential_id": 8532, "card_uid": "04A3B2C1", "stage": "authorized", "authorized_at": "..." },
    { "event_id": "uuid", "type": "attendee_access",  "credential_id": 8532, "card_uid": "04A3B2C1", "stage": "door_closed", "authorized_at": "...", "door_open_at": "...", "door_closed_at": "..." },
    { "event_id": "uuid", "type": "standing_access",  "card_uid": "04A3B2C1", "stage": "door_open", "authorized_at": "...", "door_open_at": "..." },
    { "event_id": "uuid", "type": "keyholder_access", "user_id": 12, "stage": "door_open", "authorized_at": "...", "door_open_at": "..." },
    { "event_id": "uuid", "type": "member_exit",      "card_uid": "04A3B2C1", "stage": "authorized", "authorized_at": "..." },
    { "event_id": "uuid", "type": "unexpected_open",  "stage": "door_open", "door_open_at": "..." },
    { "event_id": "uuid", "type": "access_denied",    "credential_id": 8532, "card_uid": "04A3B2C1", "reason": "expired", "timestamp": "..." }
  ]
}
```

- Each stage's time comes from its own field (`authorized_at` / `door_open_at` / `door_closed_at`). If that field is missing or can't be parsed, the server's current time is used.
- `card_uid` is converted to upper case on arrival.
- Events with an unknown type, or ones that don't resolve (an unknown `credential_id` or `user_id`), are still acknowledged so the device stops retrying them.
- Expect several `attendee_access` events for the same `credential_id` during one session, one per entry.

Response: `202 { "accepted": ["uuid", ...], "min_firmware_version": ... }`.

### `POST /v1/doors/{door_id}/logs`

A batch of log entries, safe to resend: each `log_id` is stored only once. Taxonomy: firmware spec § Diagnostic log.

```json
{
  "logs": [
    { "log_id": "uuid", "level": "error", "category": "hardware", "reason": "door_propped",
      "message": "short human-readable string", "timestamp": "...", "context": { } }
  ]
}
```

Response: `202 { "accepted": [...], "min_firmware_version": ... }`.

**Alert emails** go to `DOOR_ALERT_EMAIL`, sent by `DoorAlertMailer`:
- Only for `category=hardware`, `level=error`, with a `reason` of `door_propped` or `door_unexpected_open`. This is a deliberate short list.
- Sent only for a new `log_id`, so a retried entry never re-alerts.
- At most one per door every 10 minutes, and at most one per request. Every entry is still stored either way, and `alert_sent_at` marks the ones that triggered an email.

### `POST /v1/doors/{door_id}/heartbeat`

```json
{ "firmware_version": "1.2.0", "uptime_s": 83920, "relay_state": "locked", "last_successful_sync": "...", "cached_credential_count": 2 }
```

Written to the error log only; nothing is stored. Response: `200 { "min_firmware_version": ... }`.

## Physical prerequisite

Fail-safe vs fail-secure strike wiring has to be confirmed against fire regulations if this door is an escape route.

## Open items

- **A second door** would need a door-mapping table. Credentials, exit-session resolution and alerting all assume one door.
- **Events stuck at `door_open`** (no `door_closed` ever arrives) aren't flagged. `AccessEvent::isStuckAtDoorOpen()` exists but nothing uses it.
- **Reporting on how long the door was open** (slow or propped entries per member or door) isn't built.
- **Alert recipient** is a single fixed address. A distribution list would be needed once more than one person should get alerts.
- **Keyholder cards:** keyholders still type a disarm PIN. Whether they should tap a card instead hasn't been decided.
- **Concurrent-access cap:** the firmware's limit on authorisations per door cycle (about 6) needs confirming against the busiest realistic changeover.
- **NFC UID length** (4 or 7 bytes) depends on the cards actually in use; see the firmware spec § NFC card readers.
