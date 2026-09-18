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
  ADD COLUMN `checked_in_by_id` int DEFAULT NULL,   -- NULL = self check-in via door PIN/card; set = reception user
  ADD COLUMN `checked_in_method` varchar(20) DEFAULT NULL, -- door_pin | door_card | manual
  ADD COLUMN `checked_out_at` datetime DEFAULT NULL,
  ADD COLUMN `checked_out_method` varchar(20) DEFAULT NULL, -- door_card | manual — only door_card is wired up in this phase; manual reserved for a possible future staff "mark left" action, not built now
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
- Uniqueness: PIN must be unique among attendees with `pin_status='active'` for the same door at overlapping/near-term windows, **and** against any value currently held in `user.keyholder_pin` (§ Keyholder disarm PIN) — the two PIN pools must never collide. If one event maps to one door, scope uniqueness by `event_id`'s door; if multiple doors, add `door_id` resolution via event → venue/door mapping (not detailed here — plug into your existing model). `user.card_uid` (§ Card-based entry) lives in a completely separate value space (NFC UID, not a 6-digit number) so it never needs to be checked against either PIN pool.
- `checked_out_at`/`checked_out_method` are new in this revision, added for the internal exit reader (§ Exit reader). They're set once, same "single completion, not a running log" shape as `checked_in_at` — but, unlike `checked_in_at`, set at the tap itself (`stage=authorized`) rather than waiting for the matching `access_event` to reach `door_closed`. See § Exit reader for why entry and exit are deliberately asymmetric here.

## PIN lifecycle

- `pin_status` transitions: `active` → `used` (on valid door entry) | `revoked` (booking cancelled) | `expired` (past valid_until + grace, background job or lazy-check).
- Grace: 5 min before event start, 5–10 min after event end. Config constant, not per-row.
- Single use: `checked_in_at IS NOT NULL` (or `pin_status='used'`) blocks reuse regardless of channel (door or manual).
- Attendee PIN generation excludes any value currently held in `user.keyholder_pin`, and vice versa
  when a keyholder PIN is (re)assigned — see § Keyholder disarm PIN. The two pools are numerically
  indistinguishable to the device (both are just a 6-digit string), so they must never collide;
  enforcing it at generation/assignment time is cheap given how small the keyholder set is.
- **A membership card is just a second key to the same lock, not a second lifecycle.** Whichever
  channel — typed PIN or tapped card (§ Card-based entry) — reaches the door first for a given
  booking is the one that consumes it: same `pin_status` transition to `used`, same
  `checked_in_at`/`checked_in_method` write, same single-use guard. There's no separate
  "card status" to track; a card tap and a PIN entry are two presentations of the identical
  attendee-credential row, so whichever arrives first naturally locks the other one out as
  `already_used`. This only applies to entry — the exit reader (§ Exit reader) isn't part of this
  lifecycle at all, it isn't gated on a booking or a PIN.

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
  `type`              VARCHAR(20) NOT NULL,       -- attendee_access | keyholder_access | access_denied | unexpected_open | member_exit
  `attendee_id`       INT DEFAULT NULL,           -- set for attendee_access / access_denied; also set for member_exit if an active session was found and closed (see § Exit reader)
  `keyholder_user_id` INT DEFAULT NULL,           -- set for keyholder_access
  `exit_user_id`      INT DEFAULT NULL,           -- set for member_exit — resolved from the tapped card_uid via user.card_uid
  `channel`           VARCHAR(10) DEFAULT NULL,   -- pin | card — set for attendee_access and access_denied only; NULL for keyholder_access/unexpected_open/member_exit (those aren't ambiguous about how they were triggered)
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
  KEY `IDX_access_event_exit_user` (`exit_user_id`),
  CONSTRAINT `FK_access_event_attendee` FOREIGN KEY (`attendee_id`) REFERENCES `attendee` (`id`) ON DELETE SET NULL,
  CONSTRAINT `FK_access_event_keyholder` FOREIGN KEY (`keyholder_user_id`) REFERENCES `user` (`id`) ON DELETE SET NULL,
  CONSTRAINT `FK_access_event_exit_user` FOREIGN KEY (`exit_user_id`) REFERENCES `user` (`id`) ON DELETE SET NULL
);
```

- One row per `event_id`, **updated in place** as `stage` advances — not appended — same
  upsert-never-regress rule the old attendee-column design already required, now backed by a real
  row instead of being an implicit rule about POST ordering.
- `type=unexpected_open` rows have neither `attendee_id` nor `keyholder_user_id` set, and no
  `authorized_at` — nothing authorized them, they start straight at `door_open_at`.
- `type=access_denied` rows are a single-stage write (`stage` stays `NULL`) — `denied_reason`
  carries what used to just be called `reason`. `channel` (`pin`/`card`) records which input
  method produced the denial, now that there are two.
- `type=member_exit` rows (§ Exit reader) progress `authorized` → `door_open` → `door_closed` the
  same shape as `attendee_access`/`keyholder_access` — the tap itself writes `stage=authorized`
  immediately, same as any other authorization. `exit_user_id` is set as soon as the card resolves
  to a user; `attendee_id` is only set if that user turned out to have an active session to close.
  Unlike `attendee_access`, the attendee-row mutation (`checked_out_at`/`checked_out_method`)
  happens right there at `stage=authorized`, not gated on `door_open`/`door_closed` — see § Exit
  reader for why. The later stages still get written and are still useful (timing, the propped-door
  alarm), they just aren't load-bearing for whether the checkout "counts."
- `updated_at` gives a cheap "what's stuck at `door_open`" query for free — see the matching Open
  item below on whether anything should act on that automatically.
- `type=attendee_access` reaching `door_closed` still flips `attendee.checked_in_at` /
  `checked_in_by_id` / `checked_in_method` / `pin_status` exactly as described under § Schema
  changes — `attendee` above — this table is the detailed log behind that summary, not a
  replacement for it. `type=member_exit` flips `checked_out_at`/`checked_out_method` at
  `stage=authorized` instead (§ Exit reader) — the one deliberate asymmetry between the two
  directions in this whole design.

## Concurrent access — one door-open cycle, multiple people

New in this revision, and the main reason NFC cards are being added at all: **two people arriving
together must both end up individually checked in, even though the door only physically opens
once.** The keypad already made this awkward — only one person can be typing a PIN at a time, and
whoever isn't typing just tailgates through unrecorded. A card reader removes that bottleneck
(everyone taps in a couple of seconds, no queue), but only if the door-side state machine stops
assuming "one authorization ⇒ one physical door cycle."

**The rule going forward: any number of independent authorizations (PIN or card, attendee or
keyholder) can be attributed to the same physical door-open cycle, as long as they occur while
that cycle is already under way.** Concretely, from the server's point of view nothing changes
about how a single `access_event` row is written or upserted — this section just removes an
assumption the server was previously allowed to make (that at most one `attendee_access` or
`keyholder_access` row would ever share an overlapping `door_open_at`/`door_closed_at` window) and
states the new one:

- The **first** authorization while the door is shut and idle is the one that pulses the relay and
  starts the physical cycle.
- **Every further authorization that arrives before the door has closed again** — whether the door
  has physically opened yet or not — is folded into the *same* cycle: it gets its own `event_id`,
  its own `access_event` row, and progresses through `authorized` → `door_open` → `door_closed`
  exactly like the first one, but **does not pulse the relay again** and does not start a new
  open-wait timeout.
- All rows folded into one cycle receive the **same** `door_open_at` and `door_closed_at` — they're
  reporting on the one physical event, from each authorized person's perspective.
- `pin_status`/`checked_in_at`/`checked_in_method` are set per attendee row exactly as before —
  batching is purely about how many `access_event` rows can share a door-open window, it has no
  effect on the one-row-per-booking attendee semantics.
- This applies uniformly regardless of channel: a card tap, a typed PIN, and a keyholder PIN can
  all legitimately be part of the same batch (e.g. a member taps their card, and a keyholder
  behind them types their own PIN to hold the door for a delivery — both get their own accurate
  record, one relay pulse).
- The propped-door alarm threshold for a batch is whichever is **longest** among its members —
  practically, this only ever matters when a keyholder is part of the batch (10 minutes, § Door
  position sensing in the firmware spec), since attendee-only and card-only batches all share the
  same 20s attendee threshold anyway.
- Full state-machine detail (the bounded pending-authorization list, the join-vs-new-cycle
  decision, threshold upgrading) lives in `door-access-firmware-spec.md` § Door position sensing —
  this section only establishes the data-model-level contract the server needs to accept.

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

## Card-based entry (NFC)

The primary out-of-hours access method going forward: a member with an active booking taps their
membership card at the door instead of (or as well as — both stay available) typing their PIN.
Deliberately **not** a new, parallel credential system — a card is a second way to present the
*same* attendee-credential row a PIN already represents (§ PIN lifecycle), which is what makes
this a small addition rather than a second access-control model to maintain.

```sql
ALTER TABLE `user`
  ADD COLUMN `card_uid` varchar(32) DEFAULT NULL,
  ADD UNIQUE KEY `UNQ_user_card_uid` (`card_uid`);
```

- Lives on `user`, not `attendee` — a physical card belongs to the person and is issued once, not
  reissued per booking. Whether *this* tap unlocks the door still depends entirely on whether the
  member currently has a valid, unused attendee credential for this door — the card just identifies
  who's asking, exactly the same role a typed PIN plays today, just without the typing.
- Nullable — most members won't have a card issued immediately; entry keeps working via PIN alone
  for anyone without one.
- Stored as the reader's native UID, uppercase hex, no separators (e.g. `"04A3B2C1"`) — pick one
  canonical format now so an admin-entered UID and a device-read UID are byte-for-byte comparable
  without normalisation logic on either side. See `door-access-firmware-spec.md` § NFC card
  readers for the exact byte length the chosen chip returns (4 or 7 bytes depending on card type).
- No uniqueness conflict with `pin`/`keyholder_pin` to design around — an NFC UID and a 6-digit PIN
  live in disjoint value spaces and are matched via entirely different physical input channels
  (reader vs. keypad), so nothing needs cross-checking the way the two PIN pools do.
- **Admin UX for registering a card is not fully designed here** — see Open items. The proposed
  flow: the door's existing local status page (`door-access-firmware-spec.md` § Local
  status/config web server) already has everywhere it needs to show a `%LAST_CARD_UID%` token once
  a reader exists; a staff member taps the new card at the door, reads the UID off that page, and
  types it into a plain text field on the member's admin profile (same pattern as
  `user.keyholder_pin` today). This avoids inventing a second device role (an "enrolment reader")
  just for this — reusing the door's own entry reader as a read-only UID display is enough for v1.

**Device sync** extends the existing `GET /v1/doors/{door_id}/credentials` response — each
credential entry gains an optional `card_uid` (present only if the linked member has one
registered), sitting alongside its `pin`:

```json
{
  "credential_id": 8532,
  "pin": "483920",
  "card_uid": "04A3B2C1",
  "valid_from": "2026-09-08T14:55:00Z",
  "valid_until": "2026-09-08T16:05:00Z",
  "status": "active"
}
```

Same authoritative-full-replace rule as everything else in this response — a card tap is checked
against exactly this cached list, matched on `card_uid` instead of `pin`, but otherwise runs
through the identical valid-window / single-use / `access_denied` logic already speced for PIN
entry. **Matching order at the reader:** trivial in this case, since PIN (keypad) and card (NFC
reader) are two different physical peripherals firing independent events — there's no shared
buffer to arbitrate between them the way keyholder-vs-attendee PIN matching needs one on the
keypad.

## Exit reader (internal NFC)

A second, internal NFC reader — deliberately made **more prominent than the existing manual
"push to exit" button**, not a replacement for it (the button stays as a fallback, e.g. for a
visitor with no card at all). Tapping *any* known card against it opens the door, unconditionally.

**This is intentionally not gated on membership or booking validity at all** — same trust model as
the push-button it sits alongside: if you're inside the building, you can leave. A member whose
membership has lapsed, or who never had an active booking today, still gets the door open on tap.
The only thing the tap additionally does, beyond opening the door, is *identify* who left, so their
session can be closed out if they had one — that's a reporting bonus, never a gate.

- Because it isn't gated, this reader has **no relationship to `attendee.pin`/`pin_status` at all**
  — don't reuse the entry-side credential cache for it. It needs its own, much simpler cache: every
  user in the system who currently has a `card_uid`, full stop, no membership/booking filtering.
- **Device sync:** a new sibling array on the same `/credentials` response, present only for a door
  configured with an internal reader:
  ```json
  {
    "server_time": "...",
    "credentials": [ /* unchanged, as above */ ],
    "keyholders": [ /* unchanged */ ],
    "exit_cards": [
      { "user_id": 42, "card_uid": "04A3B2C1" }
    ]
  }
  ```
  Same authoritative-full-replace rule as `credentials`/`keyholders`. This can be a genuinely large
  list (every card ever issued, active member or not) — that's fine, it's two small fields per
  row and this only downloads to doors that actually have an internal reader wired up.
- **On a tap that resolves to a known `card_uid`:** pulse the relay immediately (§ Concurrent
  access above still applies — if the main door-open cycle is already under way from an entry tap,
  this just joins it rather than pulsing again), and queue an `access_event` of
  `type=member_exit` carrying the resolved `exit_user_id`. An **unrecognised** UID at this reader
  is simply ignored (no relay pulse, no event, not even an `access_denied` row) — unlike the entry
  reader, there's no "wrong" answer worth recording here beyond "not one of ours," and the physical
  push-button next to it has never distinguished who pressed it either.
- **Resolving and closing the "active session" happens at `stage=authorized` — the tap itself,
  not the door physically closing.** The server looks up the **most recent `attendee` row for
  `exit_user_id` where `checked_in_at IS NOT NULL AND checked_out_at IS NULL`** and sets
  `checked_out_at=authorized_at`, `checked_out_method='door_card'`, recording that row's id back
  onto the `access_event.attendee_id` column, all as part of processing the `authorized`-stage
  POST. If no such row exists (they never checked in via this system today — a keyholder just
  leaving, say, or a lapsed member let in on a guest basis), the `member_exit` row is still written
  with `exit_user_id` set and `attendee_id` left `NULL` — the tap is still worth logging, there's
  just no session to reconcile against. The event still progresses through `door_open`/
  `door_closed` afterwards exactly as before (timing visibility, propped-door alarm) — those later
  stages just no longer gate whether the checkout counts.
- **Why exit doesn't wait for `door_closed` the way entry waits for it — this was a deliberate,
  reconsidered choice, not the original design:** entry gates on `door_closed` because a PIN/card
  that's valid but never actually walked through must not falsely mark someone present. Exit's
  failure mode runs the other way. Picture a volunteer propping the door open while ten people
  filter out, each tapping the exit reader as they pass — that's **one long door-open cycle
  shared by all ten `member_exit` events** (§ Concurrent access). Gating checkout on `door_closed`
  would leave every one of them showing as still-present until that single shared door-close
  finally fires, however long the volunteer holds it — and if the door is left open long enough to
  trip the propped-door alarm, or doesn't cleanly close at all (sensor fault, someone re-props it
  before it fully latches), some or all of those ten could be left checked-in indefinitely despite
  having plainly left. A card tap at the internal reader is already strong evidence someone is
  physically at the door on their way out — good enough to trust immediately, unlike a PIN typed
  at a keypad that could be walked away from. Completing the checkout at the tap means each of the
  ten is correctly recorded the moment they present their card, independent of what the door (or
  anyone else in the batch) does afterwards.
- **Alarm threshold while `OPEN[member_exit]`:** reuse the keyholder's 10-minute threshold rather
  than the attendee 20s one — someone leaving with kit, or holding the door for others on their way
  out, is exactly as ordinary as a keyholder propping it on the way in. See § Door position sensing
  in the firmware spec for exactly how a batch's threshold is chosen when multiple types mix.
- Log `auth`/`info` `member_exit_card_used` the moment the tap resolves (`context_a = exit_user_id`)
  — audit-worthy on its own, same reasoning as `keyholder_pin_used` for the entry side, independent
  of whether an active session was actually found to close.

## API — server exposes to door controller

Auth: `Authorization: Bearer <device_api_key>` per physical door, scoped to its own door/event(s) only.

**OTA note:** every response below should also carry a `min_firmware_version` field (integer,
`YYYYMMDDXX`) alongside its existing payload — see `door-access-firmware-spec.md` for the full
OTA design this feeds into. Not broken out as separate fields per-endpoint here since it's the
same value on every response.

### `GET /v1/doors/{door_id}/credentials`

Returns active + near-future (e.g. next 2h) PIN/card-bearing attendees for this door.

```json
{
  "server_time": "2026-09-08T14:32:00Z",
  "credentials": [
    {
      "credential_id": 8532,          // attendee.id
      "pin": "483920",
      "card_uid": "04A3B2C1",         // § Card-based entry — omitted if the member has no card registered
      "valid_from": "2026-09-08T14:55:00Z",
      "valid_until": "2026-09-08T16:05:00Z",
      "status": "active"
    }
  ],
  "keyholders": [
    { "user_id": 12, "pin": "913204" }   // see § Keyholder disarm PIN — no valid_from/valid_until,
                                          // a standing credential rather than a per-booking one
  ],
  "exit_cards": [
    { "user_id": 42, "card_uid": "04A3B2C1" }   // § Exit reader — every registered card, no
                                                  // membership/booking filtering; only present for
                                                  // a door configured with an internal reader
  ]
}
```
Support `If-None-Match` / `ETag` → `304` when unchanged.

### `POST /v1/doors/{door_id}/events`

Batched, idempotent on `event_id` (client-generated UUID, not attendee.id or user.id). Each event
maps to one `access_event` row (§ Access event log). `attendee_access`, `keyholder_access`,
`unexpected_open`, and `member_exit` all arrive in up to three separate POSTs against the **same**
`event_id`, one per stage, as the door-position sensor observes the physical access happen (see
`door-access-firmware-spec.md` § Door position sensing) — this is an **upsert keyed on
`(event_id, stage)`**, not a plain create: process a stage only if it's later than (or equal to,
for a harmless retry) whatever stage is already recorded for that `event_id`, never earlier. A
delayed/reordered retry of an old stage arriving after a newer one has already been applied is a
no-op, not a regression. Remember (§ Concurrent access above): several independent `event_id`s can
legitimately share the same `door_open_at`/`door_closed_at` values now — that's expected, not a
sign of duplicate/confused events.

```json
{
  "events": [
    { "event_id": "uuid", "type": "attendee_access",  "credential_id": 8532, "channel": "pin",  "stage": "authorized",   "authorized_at": "..." },
    { "event_id": "uuid", "type": "attendee_access",  "credential_id": 8532, "channel": "pin",  "stage": "door_open",    "authorized_at": "...", "door_open_at": "..." },
    { "event_id": "uuid", "type": "attendee_access",  "credential_id": 8532, "channel": "pin",  "stage": "door_closed",  "authorized_at": "...", "door_open_at": "...", "door_closed_at": "..." },
    { "event_id": "uuid", "type": "attendee_access",  "credential_id": 9101, "channel": "card", "stage": "authorized",   "authorized_at": "..." },
    { "event_id": "uuid", "type": "attendee_access",  "credential_id": 9101, "channel": "card", "stage": "door_open",    "authorized_at": "...", "door_open_at": "..." },
    { "event_id": "uuid", "type": "attendee_access",  "credential_id": 9101, "channel": "card", "stage": "door_closed",  "authorized_at": "...", "door_open_at": "...", "door_closed_at": "..." },
    { "event_id": "uuid", "type": "keyholder_access", "user_id": 12,        "stage": "authorized",   "authorized_at": "..." },
    { "event_id": "uuid", "type": "keyholder_access", "user_id": 12,        "stage": "door_open",    "authorized_at": "...", "door_open_at": "..." },
    { "event_id": "uuid", "type": "keyholder_access", "user_id": 12,        "stage": "door_closed",  "authorized_at": "...", "door_open_at": "...", "door_closed_at": "..." },
    { "event_id": "uuid", "type": "unexpected_open",                        "stage": "door_open",    "door_open_at": "..." },
    { "event_id": "uuid", "type": "unexpected_open",                        "stage": "door_closed",  "door_open_at": "...", "door_closed_at": "..." },
    { "event_id": "uuid", "type": "member_exit",      "card_uid": "04A3B2C1", "stage": "authorized",  "authorized_at": "..." },
    { "event_id": "uuid", "type": "member_exit",      "card_uid": "04A3B2C1", "stage": "door_open",   "authorized_at": "...", "door_open_at": "..." },
    { "event_id": "uuid", "type": "member_exit",      "card_uid": "04A3B2C1", "stage": "door_closed", "authorized_at": "...", "door_open_at": "...", "door_closed_at": "..." },
    { "event_id": "uuid", "type": "access_denied", "credential_id": 8532, "channel": "pin", "reason": "expired|not_found|already_used", "timestamp": "..." }
  ]
}
```
- Every event creates or updates one `access_event` row keyed on `event_id`, setting `type` and
  whichever of `attendee_id` (from `credential_id`) / `keyholder_user_id` (from `user_id`) /
  `exit_user_id` (resolved server-side from `card_uid`, for `member_exit`) applies.
- `channel` (`pin`/`card`) is carried on `attendee_access` and `access_denied` payloads only — it
  records which physical input produced the event, purely for reporting; it has no effect on which
  fields get set or when. `keyholder_access`/`unexpected_open`/`member_exit` don't send it — none
  of them are ambiguous about how they were triggered.
- `stage=authorized` (`attendee_access`/`keyholder_access`/`member_exit` only): server sets
  `authorized_at`. For `attendee_access` specifically, also sets `pin_status='used'` on the
  attendee row — same as before, reuse-prevention doesn't wait on the physical outcome, and applies
  identically whether `channel` is `pin` or `card`. Not yet "checked in." **For `member_exit`
  specifically, this stage is also where checkout completes** (§ Exit reader) — the server resolves
  `card_uid → exit_user_id`, finds that user's active session if one exists, and sets
  `checked_out_at=authorized_at`, `checked_out_method='door_card'` right here, not waiting for
  `door_closed`. This is the one place entry and exit deliberately diverge in this table.
- `stage=door_open`: server sets `door_open_at` on the `access_event` row. Still not "checked in"
  for `attendee_access`/`keyholder_access` — this stage exists for timing/tailgating visibility,
  not attendance. `unexpected_open` has no `authorized` stage at all; it starts here. For
  `member_exit`, checkout has already completed at `authorized` — this stage is purely informative
  from here on.
- `stage=door_closed`: server sets `door_closed_at`. For `attendee_access`, this is also the point
  the linked `attendee` row gets `checked_in_at=door_closed_at`, `checked_in_by_id=NULL`,
  `checked_in_method='door_pin'` or `'door_card'` (per `channel`). For `member_exit`, the attendee
  mutation already happened back at `stage=authorized` (§ Exit reader) — this stage just completes
  the `access_event` row itself, same as it does for everything else. `keyholder_access` and
  `unexpected_open` have no attendee to update at any stage, the `access_event` row itself is the
  complete record for those.
- `access_denied` is unchanged in shape — no stage, always a single POST, `denied_reason` on the
  `access_event` row, now with `channel` alongside it.

Response: `202 { "accepted": ["uuid", ...] }` — accepting the batch, not implying every stage in it
was newer than what the server already had; a client doesn't need to distinguish "applied" from
"harmless no-op," both ack the same way.

### `POST /v1/doors/{door_id}/heartbeat`

```json
{ "firmware_version": "1.2.0", "uptime_s": 83920, "relay_state": "locked", "last_successful_sync": "...", "cached_credential_count": 2 }
```
Response: `200 { "min_firmware_version": 2026091400 }` — was a bare `204` originally, but every
response needs to carry `min_firmware_version` (§ OTA note above) and a `204` can't have a body,
so this now always returns a minimal JSON body instead.

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

Moved to its own file, `door-access-firmware-spec.md` — that document now lives in the actual
PlatformIO project this firmware is built from, not this repo:

```
/Documents/PlatformIO/Projects/y-wal/spec.md
```

(referenced by that name throughout this file — every `door-access-firmware-spec.md` mention
below means that path). It covers the full device-side brief (PlatformIO/Arduino project
structure, credential sync/keypad/relay logic, and the OTA design for shipping firmware + SPIFFS
updates over the same API). This file (in the `CardiClimbing` server repo,
`/Users/admin/Documents/CardiClimbing/door-access-spec.md`) stays the server-side source of truth
— schema, PIN lifecycle, API contract; the firmware doc restates the API-facing bits it depends on
so it can be handed to a build agent on its own, and cross-references back to this exact path.

## Non-negotiable physical check

Fail-safe vs fail-secure catch wiring — confirm against local fire code if this door is an egress path. Not an API concern but blocks the hardware build.

## Open items

- Door-to-event mapping if venue has >1 door.
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
- **Admin UX for registering `user.card_uid` isn't fully designed** (§ Card-based entry) — the
  proposed flow (read the UID off the door's local status page, type it into a text field on the
  member's profile) reuses existing infrastructure but hasn't been reviewed; a dedicated
  desktop/USB enrolment reader, or a phone-based NFC-read admin page, are reasonable alternatives
  if the "walk to the door and read a screen" flow turns out to be impractical in practice.
- **Whether keyholders should also get cards** (tap instead of typing their disarm PIN) isn't
  addressed — this revision only adds cards for booking-attendee entry and for the unconditional
  exit reader. Keyholder PIN entry is unchanged. Worth revisiting once cards are in use and the
  PIN-typing friction for keyholders becomes a real complaint rather than a hypothetical one.
- **Exit-session resolution is single-door-scoped by construction** (same "no door-mapping table
  yet" assumption as everywhere else in this spec) — "most recent unclosed `attendee` row for this
  user" doesn't need to consider *which* door they entered through while there's only one. Revisit
  alongside the existing door-to-event mapping open item if a second door is ever added.
- ~~Checkout is gated on `door_closed`, matching the entry side's symmetry~~ — **reconsidered and
  changed**: checkout now completes at `stage=authorized` (§ Exit reader), specifically so a
  volunteer propping the door for a group exiting together doesn't leave everyone's session
  hanging on one shared, possibly-delayed door-close. Left here struck through for the same reason
  as the other resolved items in this document.
- **Concurrent-access batch size isn't bounded here** (§ Concurrent access) — the firmware spec
  proposes a small fixed cap (e.g. 6) on how many authorizations can share one physical door cycle;
  confirm that number is generous enough for the busiest realistic arrival scenario (a class
  finishing and the next one arriving at the same time, say) without being large enough to let a
  malfunctioning reader spam batches indefinitely.
- **NFC UID format assumes a fixed hex-string shape** (§ Card-based entry) — confirmed once the
  actual reader chip is chosen and its typical card population (Mifare Classic vs. NTAG/Ultralight,
  4-byte vs. 7-byte UIDs) is known; see `door-access-firmware-spec.md` § NFC card readers.
