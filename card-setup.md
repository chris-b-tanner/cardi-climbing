# Card setup spec (slim)

Companion to `door-access-spec.md` (which owns `user.card_uid` itself, and the door's own
credential sync) — this document covers a **separate physical device**, purpose-built for staff to
(a) **link** a new card to a member and (b) **verify** that a card someone's holding really is the
one on file for them. Not a door: it never opens anything, has no relay, and isn't gated on a
booking. Its only job is turning "hold a card near a reader" into a UID on the server and a
name on its own screen, so staff never types a UID by hand and never has to guess whether two
members swapped cards.

## Why this exists, not just the admin text field

The admin edit screen already has a plain `cardUid` text input (`AdminController::editUser()`) —
that stays, as the fallback for "type it in remotely, no station nearby." This spec adds the fast
path: stand at the reception desk, tap the card, watch the name appear, done. It also adds
something the text field can't do at all — **verification**: confirming a card already on file
still belongs to the person holding it, without anyone having to read a UID out loud and compare
it by eye.

## No new table — everything lives in `user.card_uid` itself

Deliberately not a `card_session` table. There is exactly one physical station (§ Assumptions), so
"is anything currently armed, and for whom" can be answered by a single query against `user` —
**reusing the column's own uniqueness for free** — instead of standing up a second piece of state
that has to be kept in sync with the first.

A handful of reserved, non-hex string values get written into `card_uid` *in place of* a real UID
while a link/verify is in flight, then replaced with either a real UID or the original value once
it resolves. This works safely because every real UID is validated elsewhere
(`AdminController::normalizeCardUid()`) as `^[0-9A-F]{8,32}$` — pure uppercase hex, no separators —
so any sentinel containing a `:`, `|`, or a non-hex letter can **never** collide with a real,
legitimately-stored card UID. No flag column, no extra table, just a small, deliberately
non-hex-shaped string convention on the one column that already exists.

```
Idle:                          <a real hex UID>, or NULL
Armed for link:                PENDING:<original-or-empty>
Armed for verify:               PENDING_VERIFY:<original>
Verify resolved, mismatch:      MISMATCH:<original>|<tapped>
```

- **`PENDING:<original-or-empty>`** — arming a link remembers whatever was there before (empty if
  this member never had a card), so a failed/conflicting attempt has something to roll back to
  instead of guessing.
- **`PENDING_VERIFY:<original>`** — verify can only be armed when a real card is already on file,
  so there's always an `<original>` to embed. This is the one case where overwriting the column
  might look destructive at a glance — it isn't, because every resolution path below restores it.
- **`MISMATCH:<original>|<tapped>`** — the *only* sentinel that survives past the tap itself,
  and only for as long as it takes the admin browser's next poll to read it (see § Resolving a
  verify below) — restoring `<original>` is a side effect of that read, not a separate cleanup
  step someone has to remember to run.
- A **match** on verify needs no sentinel at all: the server just writes `<original>` straight
  back the instant it confirms the tap equals it — from the browser's point of view, "the pending
  marker vanished and the value underneath is exactly what I remembered arming with" *is* "matched."
- **Migration needed: widen `user.card_uid` from `VARCHAR(32)` to `VARCHAR(96)`.** Worst case is
  `MISMATCH:` (9) + a 32-char original + `|` (1) + a 32-char tapped value = 74 chars; 96 leaves
  headroom without a second migration if the format grows a field later. Everything else about the
  column (nullable, unique) is unchanged — this is the entire schema footprint of this whole spec.

## Assumptions locked in

- Single station for now: same "one hardcoded id" pattern as `door_id = 1` in
  door-access-spec.md, except there isn't even an id to hardcode — the station has nothing to poll
  by id at all, it just asks "who's currently pending?" (§ API below). Add a real
  station-identity concept later if a second desk needs one.
- Staff-initiated only, not a public kiosk. Every arm happens from the admin UI, already
  authenticated as `ROLE_TEAM`, for one specific, already-known member. The station never lets a
  walk-up figure out whose card they're holding — see § Privacy.
- **Only one member can be "pending" (either mode) across the whole system at a time** — enforced
  at arm time, not by the database: arming a new link/verify first clears whatever other row is
  currently in a `PENDING%`/`MISMATCH:%` state (restoring *that* row from its own embedded
  original first, exactly as if its own session had been cancelled). This is the direct
  replacement for "only one `pending` `card_session` row" from a table-based design — same
  invariant, enforced by "clear the old one before writing the new one" instead of a second table.
- All times UTC, nothing here is date-sensitive enough for that to matter beyond consistency with
  the rest of the app.

## API — server exposes to the card station device

Auth: `Authorization: Bearer <card_station_api_key>` (a new `CARD_STATION_API_KEY` env var — a
different secret from the door's `DOOR_API_KEY`, since this is a different physical device with a
different trust boundary: it lives at a staffed desk, not bolted to an access-controlled door).

### `GET /v1/card-station/pending`

Polled by the station every **1–2 seconds** — this is an interactive, watched-in-person flow, not
a background sync, so it needs to feel instant rather than tolerate the door's 20–30s cadence. No
id in the path — there's at most one pending row, full stop.

```json
// Nothing armed right now:
{ "server_time": "2026-09-22T10:00:00Z", "pending": null }

// Armed (mode read straight off the sentinel prefix):
{
  "server_time": "2026-09-22T10:00:02Z",
  "pending": { "mode": "link", "user_name": "Chris Tanner" }
}
```

- `user_name` is the only identifying info the station ever receives — no email, no member id.
  It's there purely so the screen can prompt "Link card for Chris Tanner — tap now" /
  "Verify card for Chris Tanner — tap now".
- The station always displays whatever this endpoint currently says, full stop — no local "remember
  the last poll" state to reconcile. If the response says `null`, the screen goes back to idle
  immediately, even if a tap is mid-flight (the next endpoint makes that race harmless).

### `POST /v1/card-station/scan`

Fired once per tap, immediately after `card_reader.cpp`-style UID capture (same PN532/NFC reading
approach as the door's entry reader — see § Hardware note for why reusing that exact
UID-formatting logic matters).

```json
{ "card_uid": "B0A9FF5C" }
```

Server behaviour — finds whichever single row is currently `PENDING%` (there's at most one; if
none, respond `expired` — the browser side must have been cancelled/timed out between polls):

- **Link:** if `card_uid` isn't already registered to a different user, write it straight in
  (`user.card_uid = card_uid`) → respond `linked`. If it's already someone else's real UID, restore
  the embedded original (or `NULL`) → respond `conflict`.
- **Verify, tap equals the embedded original:** write the original straight back (a no-op value,
  clearing the `PENDING_VERIFY:` wrapper) → respond `matched`.
- **Verify, tap differs:** write `MISMATCH:<original>|<card_uid>` (deliberately *not* restored yet
  — see § Resolving a verify) → respond `mismatch`.

```json
{ "result": "linked" }
{ "result": "conflict" }
{ "result": "matched" }
{ "result": "mismatch" }
{ "result": "expired" }
```

The station's screen never needs more than this five-value enum — see § Privacy for exactly what
text each one maps to. It never learns whose card it actually was on a mismatch; that answer is
for the browser only.

## API — server exposes to the admin UI

Auth: normal session auth, `ROLE_TEAM`, CSRF-protected on the mutating calls — same as every other
`/admin/...` action in this app, nothing device-specific here.

### `POST /admin/users/{id}/card-scan`

Body: `{ "mode": "link" | "verify" }`. Clears any other row's pending/mismatch sentinel first (§
Assumptions), then writes this user's own `PENDING:`/`PENDING_VERIFY:` sentinel. `verify` is
rejected with a flash error if the user has no `card_uid` to verify against. No response body
needed beyond 200 — the calling page already knows which user it armed and immediately starts
polling the next endpoint.

### `GET /admin/users/{id}/card-scan`

Polled by the browser every ~1s while the "waiting for tap" modal is open.

```json
{ "status": "pending" }
{ "status": "linked", "cardUid": "B0A9FF5C" }
{ "status": "matched" }
{ "status": "mismatch", "matchedUserName": "Jane Doe" }   // null matchedUserName = tapped card is unregistered
{ "status": "conflict" }
{ "status": "idle" }                                       // nothing pending for this user — expired, cancelled, or never armed
```

**Resolving a verify** happens as a side effect of *this* endpoint, not the station's POST: seeing
`MISMATCH:<original>|<tapped>` on this user's row, it (a) looks up `tapped` via
`UserRepository::findOneByCardUid()` for `matchedUserName` (null if unregistered), (b) atomically
restores `card_uid = original` (an `UPDATE ... WHERE card_uid = 'MISMATCH:<original>|<tapped>'`
guard makes this safe against a duplicate/racing read re-doing it), and (c) returns `mismatch`
with that name. The very next poll — from this tab or any other — just sees the row back to a
plain real UID and reports `idle`, so the mismatch reveal fires exactly once, right when a
human is actually looking at it.

The modal's copy per status:
- `linked` → "Card linked to {member}." — closes, updates the visible card-UID field/badge.
- `matched` → "✓ This card belongs to {member}."
- `mismatch` (with name) → "✗ This card is registered to Jane Doe, not {member}. Nothing changed —
  reassign it from Jane Doe's profile first if that's not intended."
- `mismatch` (no name) → "✗ This card isn't registered to anyone. Nothing changed."
- `conflict` → "That card is already registered to another member. Nothing changed." — offers a
  staff-only "Reassign to {member} anyway" follow-up button, a normal separate confirmed action
  (§ Open items), not something this flow resolves automatically.
- `idle`/timeout → "Timed out — try again."

### `DELETE /admin/users/{id}/card-scan`

Cancels a still-pending arm for this user — restores the embedded original and clears the
sentinel. Called automatically when the modal is closed/navigated away from. A no-op (not an
error) if it's already resolved, or if some other user's arm has since superseded it.

## UI flow

**Contact edit screen** (`templates/admin/users/edit.html.twig`, next to the existing `cardUid`
text field): a **"Scan card"** button alongside the manual input — arms `link`, opens the polling
modal above. On `linked`, the modal closes and the text field is populated with the new UID
client-side, so the existing save button/flow is unchanged — this button is purely an alternative
way to *fill in* the field, not a parallel save path. The manual field and its existing validation
(`AdminController::normalizeCardUid()`) stay exactly as they are, for whenever the station isn't
available or an admin is doing this remotely.

**Contact show screen** (`templates/admin/users/show.html.twig`, on the access-card visual): a
**"Verify"** button, shown only when `user.cardUid` is already set — arms `verify`, same modal.
Nothing on this screen is editable from a verify outcome; it's a read-only check. Fixing a
`mismatch`/`conflict` always means navigating to the relevant member's edit screen and using "Scan
card" there — deliberately no one-click "steal this card" action buried in a verify result.

## Privacy: what the station's screen shows

- **Idle:** a neutral "Y Wal" idle screen — nothing armed, nothing to see.
- **Armed:** "{Link/Verify} card for {member's name} — tap now." Always the *target* member's
  name — the one staff already selected in the browser — never anyone else's.
- **After a tap**, exactly one of:
  - `linked` → "✓ Linked to {member's name}."
  - `conflict` → "✗ Already registered to someone else." (no name)
  - `matched` → "✓ This is {member's name}'s card."
  - `mismatch` → "✗ Not {member's name}'s card." (no name — the station can't tell "someone else's"
    from "no one's" and doesn't need to)
  - `expired` → "Session timed out."

The **only** place "whose card is this, actually" ever gets answered is `matchedUserName` on the
authenticated `GET /admin/users/{id}/card-scan` response — consumed by a staff member already
looking at a specific other member's profile, never pushed to the station's screen, which anyone
walking past a reception desk could see.

## Hardware note

Not designed in detail here — this stays a server/API/UI spec, same split as
door-access-spec.md/door-access-firmware-spec.md. When built, expect:

- A small networked (WiFi is fine — this sits at a staffed desk, not on the door's dedicated
  ethernet drop) microcontroller + NFC reader + small display (an SSD1306 OLED or similar small
  TFT is plenty for two lines of text and a checkmark/cross glyph) + no relay, no door-position
  sensor, none of the door's physical I/O.
- Reuse the door firmware's exact UID-capture/formatting approach (`card_reader.cpp`'s
  `formatUid()`: uppercase hex, no separators) so a UID read by either device is byte-for-byte the
  same string — no per-device normalisation quirks to keep in sync.
- A companion `card-station-firmware-spec.md`, living in its own PlatformIO project the same way
  `door-access-firmware-spec.md` lives in `/Documents/PlatformIO/Projects/y-wal/spec.md` — likely
  its own project rather than a mode of the door firmware, since the two devices share almost no
  physical I/O beyond "has an NFC reader."

## Open items

- **Reassigning a card already registered to someone else** (the `conflict` follow-up) is
  explicitly scoped out of this flow — proposed as a plain, separate confirmed admin action
  (clearing the old owner's `card_uid` and setting the new one in one transaction), not something
  the scan flow itself does automatically.
- **The "clear any other pending row first" rule (§ Assumptions) is an application-level
  invariant, not a database one** — unlike the old table-based design's DB-enforced "one row",
  two different users' sentinels never collide as strings, so nothing stops two arm calls from
  racing in theory. Acceptable for a single staffed station operated by one person at a time; would
  need real locking if a second station is ever added (§ next item).
- **Multiple stations** would break the "there's only ever one pending row" assumption this whole
  no-new-table design leans on — supporting a second desk is the point at which a small
  `station_id`-aware table (closer to the earlier draft of this spec) probably becomes the right
  call again, rather than stretching the sentinel convention further.
- **Self-service verification** ("is this my card?" without staff involved) is a deliberate
  non-goal — see § Privacy. Worth reconsidering only behind a member's own login (matched against
  `app.user`, never an arbitrary tap), not as a station-floor kiosk mode.
- **Poll cadence (1–2s) and no explicit TTL on a pending sentinel** — unlike the table-based draft,
  there's no `expires_at` here, so an abandoned arm (browser tab closed without the modal's cleanup
  firing) leaves that member stuck `PENDING` until someone else's arm clears it, or it's noticed
  and cancelled manually. Worth adding a lazy "older than N minutes" check on the next arm/poll if
  this turns out to happen often in practice — not designed in here to keep the sentinel format
  from growing a timestamp field too.
- **What happens if the station itself is offline** isn't specially handled — the admin-side modal
  just sits on `pending` indefinitely rather than timing out (see previous item) — same "no
  station-unreachable detection" gap as the original draft.
