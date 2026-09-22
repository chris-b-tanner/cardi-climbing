# Card setup spec

Companion to `door-access-spec.md` (the door's own credential sync, and the general "cards are a
second presentation of the same attendee credential" model) — this document owns the *identity and
lifecycle* of a physical membership card itself: `access_card` (replacing what an earlier revision
of `door-access-spec.md` put directly on `user.card_uid`), plus a separate physical device,
purpose-built for staff to (a) **link** a new card to a member, (b) **verify** that a card someone's
holding really is the one on file for them, and (c) **lock**/**unlock** a card without losing who
it belongs to.

## Why two real tables, not a field on `user`

An earlier revision of this spec (and its implementation) put the card UID directly on
`user.card_uid`, with an in-flight link/verify session encoded as reserved non-hex string prefixes
temporarily written into that same column (`PENDING:`, `RESULT_LINKED:`, etc.), specifically to
avoid a migration. That worked for the original, narrow ask, but broke down the moment a second,
*persistent* concern (locking, with a real audit trail — who deployed this card, who locked it,
when) needed to live somewhere too:

- **No audit trail is possible in a single overwritable field.** "Who locked this, and when" is a
  fact about a point in time — it needs its own row, not a string that gets replaced.
- **Every reader of the field had to become aware of every sentinel.** Locking added an 8th
  reserved prefix, and meant `UserRepository::findOneByCardUid()`, `cardUidExists()`,
  `DoorAccessService::findCredentialsForDoor()`, and the admin edit form *all* needed updating to
  stay "lock-aware" on top of already being "scan-session-aware" — the classic sign a single field
  is doing the job of several real entities.
- **A real, sharp bug surfaced from exactly this.** Rendering the manual UID field as `disabled`
  while locked (so a routine profile save wouldn't clobber the lock) meant the field was never
  submitted at all — which would have hit the "field submitted empty → clear the card" branch and
  silently wiped the lock *and* the UID on the next unrelated save (e.g. updating a phone number).
  Avoiding that meant every write path needed its own bespoke guard.
- **A card is a real entity with its own lifecycle**, independent of `User`: issued on some date, by
  some staff member; possibly locked later; possibly replaced by a different physical card
  entirely, with the old one's history worth keeping. That's what a row represents naturally.

Two tables fix all of this and, as a bonus, remove the trickiest part of the previous design: the
"whichever poll reads a result first consumes it" mechanism the session needed just to have
somewhere to put an outcome. A real `status` column doesn't need that — it's just read.

## Schema

```sql
CREATE TABLE `access_card` (
  `id`              INT AUTO_INCREMENT PRIMARY KEY,
  `user_id`         INT NOT NULL,
  `uid`             VARCHAR(32) NOT NULL,        -- uppercase hex, no separators (unchanged format)
  `status`          VARCHAR(20) NOT NULL,        -- active | locked | replaced | removed
  `deployed_at`     DATETIME NOT NULL,
  `deployed_by_id`  INT DEFAULT NULL,             -- staff who linked it (null: manual DB fixup, not a real path)
  `locked_at`       DATETIME DEFAULT NULL,
  `locked_by_id`    INT DEFAULT NULL,
  `unlocked_at`     DATETIME DEFAULT NULL,
  `unlocked_by_id`  INT DEFAULT NULL,
  `replaced_at`     DATETIME DEFAULT NULL,        -- set for `replaced` (superseded by a newer card) AND `removed` (no replacement) alike
  UNIQUE KEY `UNQ_access_card_uid` (`uid`),
  KEY `IDX_access_card_user` (`user_id`),
  CONSTRAINT `FK_access_card_user`        FOREIGN KEY (`user_id`)        REFERENCES `user` (`id`) ON DELETE CASCADE,
  CONSTRAINT `FK_access_card_deployed_by` FOREIGN KEY (`deployed_by_id`) REFERENCES `user` (`id`) ON DELETE SET NULL,
  CONSTRAINT `FK_access_card_locked_by`   FOREIGN KEY (`locked_by_id`)   REFERENCES `user` (`id`) ON DELETE SET NULL,
  CONSTRAINT `FK_access_card_unlocked_by` FOREIGN KEY (`unlocked_by_id`) REFERENCES `user` (`id`) ON DELETE SET NULL
);

CREATE TABLE `card_link_session` (
  `id`               INT AUTO_INCREMENT PRIMARY KEY,
  `user_id`          INT DEFAULT NULL,             -- NULL only for mode=lookup — see below
  `mode`             VARCHAR(10) NOT NULL,        -- link | verify | lookup
  `status`           VARCHAR(20) NOT NULL,        -- pending | linked | matched | mismatch | conflict | found | not_found | cancelled | expired
  `scanned_uid`      VARCHAR(32) DEFAULT NULL,
  `matched_user_id`  INT DEFAULT NULL,             -- verify: set when the tap belongs to a DIFFERENT known member. lookup: set to whoever it resolves to, full stop
  `created_by_id`    INT DEFAULT NULL,
  `created_at`       DATETIME NOT NULL,
  `expires_at`       DATETIME NOT NULL,
  `resolved_at`      DATETIME DEFAULT NULL,
  KEY `IDX_card_link_session_user` (`user_id`),
  KEY `IDX_card_link_session_matched_user` (`matched_user_id`),
  KEY `IDX_card_link_session_status` (`status`),
  CONSTRAINT `FK_card_link_session_user`         FOREIGN KEY (`user_id`)         REFERENCES `user` (`id`) ON DELETE CASCADE,
  CONSTRAINT `FK_card_link_session_matched_user` FOREIGN KEY (`matched_user_id`) REFERENCES `user` (`id`) ON DELETE SET NULL,
  CONSTRAINT `FK_card_link_session_created_by`   FOREIGN KEY (`created_by_id`)   REFERENCES `user` (`id`) ON DELETE SET NULL
);
```

- **`access_card` rows are never deleted or overwritten in place** — locking, unlocking, removing,
  and replacing all just update `status`/timestamps on the existing row (or, for a replacement,
  insert a new row and mark the superseded one `replaced`). That's most of the audit trail —
  "who deployed/locked/unlocked this and when" are just columns — but **who did a `remove`, and
  why, isn't a column here at all**: `CardService::remove()`/`link()`/`lock()`/`unlock()` each also
  write a `Note` (`Note::TYPE_MEMBER`) on the member's own history via the existing
  `UserService::addNote()`, worded per action ("Access card added/replaced/removed/locked/unlocked:
  ..."). That note *is* the "who and why" record for every transition — deliberately not a 5th
  `removed_at`/`removed_by` column pair, since the note already carries it and is visible right on
  the member's profile rather than needing a separate admin screen to inspect `access_card` rows.
- **`removed` vs `replaced`**: both mean "no longer current" and are excluded identically from
  `findActiveForUser()`/`findCurrentForUser()` — the only difference is intent, for the humans
  reading the history later. `replaced` means a specific newer card exists; `removed` means the
  member now has no card at all until one is linked again.
- **`uid` is unique across the whole table, forever** — once a physical card is registered, its row
  is its permanent record regardless of status. This is what makes "whose card is this, actually"
  a plain `WHERE uid = :uid` lookup with no status-aware branching, unlike the old
  sentinel-prefixed design.
- **At most one `active` row per `user_id`** is an application rule (MySQL has no native partial
  unique index) — linking a new card first marks any existing active row `replaced`.
- **`card_link_session` rows are normal, persisted, terminal-once-resolved records** — `status`
  transitions directly from `pending` to a final value and stays there; nothing needs to be
  "consumed" by whichever request reads it first, because the row simply holds the answer.
- **At most one `pending` session across the whole system at a time** (single-station assumption,
  same as the original design) — arming a new one first cancels any other still-`pending` row.
- `expires_at` gives a plain TTL (proposed: 60s) for an abandoned arm; `resolved_at` records when a
  station's tap (or a cancel) actually closed it out.
- **`mode=lookup` ("find by card") is the one case `user_id` is null** — link/verify always arm
  from an already-known member's own contact page; a lookup starts from nothing but a tap, from
  the People list, with no target member to key the row by at all. It resolves to `found`
  (`matched_user_id` set) or `not_found`, never `linked`/`matched`/`mismatch`/`conflict` — those
  outcomes only make sense when there was a specific member being linked/verified against.

## Assumptions locked in

- Single station for now, same spirit as `door_id = 1` in door-access-spec.md — the station has
  nothing to identify itself by at all, it just asks "what's the one pending session?" (§ API).
- Staff-initiated only, not a public kiosk. Every arm happens from the admin UI, already
  authenticated as `ROLE_ADMIN` (same gate as the card fields it replaces), for one specific,
  already-known member. The station never lets a walk-up figure out whose card they're holding —
  see § Privacy.
- Manual entry (typing a UID straight into the admin UI, no station involved) stays available as a
  fallback — it's a **synchronous** action against `access_card` directly (create/replace the
  active row immediately), not a `card_link_session` at all; there's nothing to wait for.
- All times UTC, consistent with the rest of the app; nothing here is date-sensitive enough for
  that to matter beyond consistency.

## API — server exposes to the card station device

Auth: `Authorization: Bearer <card_station_api_key>` (`CARD_STATION_API_KEY` env var — a different
secret from the door's `DOOR_API_KEY`, since this is a different physical device with a different
trust boundary: it lives at a staffed desk, not bolted to an access-controlled door).

### `GET /v1/card-station/pending`

Polled every **1–2 seconds** — an interactive, watched-in-person flow, not a background sync.

```json
// Nothing armed right now:
{ "server_time": "2026-09-22T10:00:00Z", "pending": null }

// Armed:
{
  "server_time": "2026-09-22T10:00:02Z",
  "pending": { "mode": "link", "user_name": "Chris Tanner" }
}
```

`user_name` is the only identifying info the station ever receives — no email, no member id, just
enough for "Link card for Chris Tanner — tap now." / "Verify card for Chris Tanner — tap now."

### `POST /v1/card-station/scan`

```json
{ "card_uid": "B0A9FF5C" }
```

Finds the one `pending` session (if none: respond `expired` — cancelled/timed out between polls).
Writes the outcome directly onto that row (`status`, `scanned_uid`, `resolved_at`, and
`matched_user_id` for a verify mismatch against a known member) and, for `link`, also
creates/updates the `access_card` row right here — no separate finalise step, since there's no
"consumed on read" trick to defer:

- **Link, UID free:** mark any existing active `access_card` for this user `replaced`; insert a new
  one (`status='active'`, `deployed_at=now`, `deployed_by_id=<session.created_by_id>`) → session
  `status='linked'` → respond `linked`.
- **Link, UID already belongs to a different user's row (any status):** session `status='conflict'`
  → respond `conflict`. Nothing written to `access_card`.
- **Verify, tap equals the target's active card's `uid`:** session `status='matched'` → respond
  `matched`.
- **Verify, tap differs:** session `status='mismatch'`, `matched_user_id` set if
  `AccessCardRepository::findOneByUid()` resolves the tapped UID to someone else's row → respond
  `mismatch`.

```json
{ "result": "linked" }
{ "result": "conflict" }
{ "result": "matched" }
{ "result": "mismatch" }
{ "result": "expired" }
```

The station's screen never needs more than this five-value enum — see § Privacy for what text each
one maps to. It never learns whose card it actually was on a mismatch; that's for the browser only.

## API — server exposes to the admin UI

Auth: normal session auth, `ROLE_ADMIN` (matching the gate the card fields already sit behind),
CSRF-protected on every mutating call.

### `POST /admin/users/{id}/card-scan`

Body: `{ "mode": "link" | "verify" }`. Cancels any other user's still-`pending` session first (§
Assumptions), then creates a fresh `card_link_session` row for this user. `verify` is rejected with
an error if the user has no active card. Response: `{ "ok": true }` — the calling page already
knows which user it armed and starts polling the next endpoint.

### `GET /admin/users/{id}/card-scan`

Polled every ~1s while the "waiting for tap" modal is open — a plain read of this user's latest
session, no side effects:

```json
{ "status": "pending" }
{ "status": "linked", "cardUid": "B0A9FF5C" }
{ "status": "matched" }
{ "status": "mismatch", "matchedUserName": "Jane Doe" }   // null matchedUserName = tapped card is unregistered
{ "status": "conflict" }
{ "status": "idle" }                                       // no session for this user at all, or already cancelled/expired
```

Modal copy per status is unchanged from the original draft:
- `linked` → "Card linked to {member}." — closes, updates the visible card badge.
- `matched` → "✓ This card belongs to {member}."
- `mismatch` (with name) → "✗ This card is registered to Jane Doe, not {member}. Nothing changed —
  reassign it from Jane Doe's profile first if that's not intended."
- `mismatch` (no name) → "✗ This card isn't registered to anyone. Nothing changed."
- `conflict` → "That card is already registered to another member. Nothing changed."
- `idle`/timeout → "Timed out — try again."

### `DELETE /admin/users/{id}/card-scan`

Cancels this user's still-`pending` session (`status='cancelled'`, `resolved_at=now`) — called
automatically when the modal is closed/navigated away from. A no-op if already resolved or if no
session exists.

### `POST` / `GET` / `DELETE /admin/card-lookup` ("find by card")

Same three-verb-one-URL shape as `/admin/users/{id}/card-scan` above, but **not** user-scoped —
there's no `{id}` yet, that's the entire point of a lookup. Gated `ROLE_TEAM`, not `ROLE_ADMIN`:
finding a member by tapping their card is a search convenience equivalent to typing their name
into the People list's own search box, not a credential-management action, so it doesn't need the
tighter gate the actual card fields (link/verify/lock/unlock) sit behind.

- `POST` arms it — no body beyond CSRF, cancelling whatever else is pending first (unconditionally;
  a lookup has no "same target" case to spare re-arming for, unlike link/verify against one member).
- `GET` polls it:
  ```json
  { "status": "pending" }
  { "status": "found", "userId": 512, "userName": "Chris Tanner" }
  { "status": "not_found" }
  { "status": "idle" }
  ```
  `found` is the one status the admin browser is expected to *act* on rather than just display —
  redirecting straight to `/admin/users/{userId}`. `not_found` just says so; nothing else happens
  automatically (see § Open items — offering a one-click "+ New person" prefilled with the scanned
  UID was considered and deliberately left for later, not built as part of this).
- `DELETE` cancels it, same as the link/verify version.

## API — server exposes to the admin UI (card lifecycle, no station involved)

Plain form POSTs, not fetch/JSON — these are one-shot actions with a redirect-and-flash result,
matching the rest of the admin app's convention (e.g. cancel-membership, delete-user), not the
live-polling modal the station flow needs.

- **`POST /admin/users/{id}/card-link-manual`** — body `uid`. Same validation
  (`^[0-9A-F]{8,32}$`) and free-uid-conflict check as the station path, applied synchronously:
  marks any existing active card `replaced`, inserts a new active row with
  `deployed_by_id=<current admin>`. No session row at all — nothing to wait for.
- **`POST /admin/users/{id}/card-lock`** — locks the user's current active card
  (`status='locked'`, `locked_at=now`, `locked_by_id=<admin>`). No-op with a flash error if there's
  no active card.
- **`POST /admin/users/{id}/card-unlock`** — reverses it (`status='active'`, `unlocked_at=now`,
  `unlocked_by_id=<admin>`). No-op with a flash error if the card isn't locked.

## UI flow

**Contact show screen** hosts every live card action — moved here (from an earlier draft that put
them on the edit screen) so they sit right next to the card visual itself, where staff are already
looking:
- The access-card graphic shows the active card's UID, with a **"Verify"** button in its
  bottom-right corner (admin-only, read-only outcome). A `locked` card renders visibly greyed out
  and labelled "· Locked" in the card's own header — locking doesn't hide identity, it hides door
  access, so the UID stays visible underneath.
- Directly below the graphic: **"Scan card"** (arms `link` via the station modal, § above — on
  success, reloads to show the freshly-linked card), **"Lock"**/**"Unlock"** (whichever applies),
  and **"Remove"** (no replacement — confirms first, since the member is left with no card at all
  until one's linked again).
- No card yet: a dashed placeholder sits where the graphic would be, with just a **"Scan card"**
  button — the same trigger, same modal, just with no existing UID to show first.

**Contact edit screen** keeps only the read-only card summary (UID, deployed date, who deployed it,
locked-since if applicable) plus a small **manual-entry form** (its own `<form>`, independent of
the main profile-save form) for typing a UID directly when the station isn't available — every
other action lives on the show screen instead.

**People list** gets a **"Find by card"** button next to "+ New person" — arms `lookup` via the
same shared modal, and on `found` redirects straight to `/admin/users/{userId}`. `not_found` just
shows a message; unlike the other modes, there's no member page to reload or link update to
reflect afterwards, so nothing else happens.

None of this lives inside the big "Edit person" form — every card action (station-driven or manual)
is its own independent POST, so saving an unrelated profile field (phone number, memo, …) can never
touch the card record as a side effect. This was the actual bug that prompted this redesign
(§ "Why two real tables" above).

## Privacy: what the station's screen shows

- **Idle:** a neutral "Y Wal" idle screen — nothing armed, nothing to see.
- **Armed, link/verify:** "{Link/Verify} card for {member's name} — tap now." Always the *target*
  member's name.
- **Armed, lookup:** "Tap a card to look up its member" — deliberately generic, since a lookup has
  no target to name.
- **After a tap**, exactly one of:
  - `linked` → "✓ Linked to {member's name}."
  - `conflict` → "✗ Already registered to someone else." (no name)
  - `matched` → "✓ This is {member's name}'s card."
  - `mismatch` → "✗ Not {member's name}'s card." (no name — the station can't tell "someone else's"
    from "no one's" and doesn't need to)
  - `found`/`not_found` (lookup) → a generic "✓ Card read." regardless of which — **even though
    finding out who it is is the entire point of this mode**, that answer is deliberately withheld
    from the station's own screen, same as every other mode. The reveal happens only once, in the
    browser of the staff member who armed it (via the redirect on `found`), not on a device sat at
    a reception desk anyone could glance at.
  - `expired` → "Session timed out."

The **only** places "whose card is this, actually" ever get answered are `matchedUserName` on the
authenticated `GET /admin/users/{id}/card-scan` response (verify) and `userName`/`userId` on
`GET /admin/card-lookup` (lookup) — both consumed by a staff member already at their own screen,
never pushed to the station's.

## Settings > Cards (report)

Read-only, `ROLE_ADMIN`, at `/admin/settings/cards` — every UID the card station has ever resolved
a scan for, merged from two sources: `access_card` (linked at some point, any status) and
`card_link_session.scanned_uid` (resolved a tap even if it never became — or no longer is — a
registration: a mismatched verify, a not-found lookup, a conflicting link attempt). The merge
happens in PHP (`AdminCardController::index()`), not a SQL `UNION` — two capped, indexed queries
or'd together into one array keyed by UID, easier to read than a hand-written cross-table query
for what's expected to be a small table at this venue's scale.

Deliberately doesn't fold in door taps (`access_event.card_uid`) — those already have their own
report (Settings > Access log, door-access-spec.md) and are a different device's history; merging
a third source here would blur two pages that are each already a complete, correct account of
their own device.

Clicking a UID goes to `/admin/settings/cards/{uid}` — the `access_card` registration details (if
any) plus every `card_link_session` row for that exact UID, most recent first. A UID with no
`access_card` row at all still gets a page (as long as at least one session mentions it) — "this
was scanned but never registered" is itself useful information, not a 404.

## Door credential sync (door-access-spec.md, updated)

`DoorAccessService::findCredentialsForDoor()` now sources `card_uid` from
`AccessCardRepository::findActiveForUser($attendee->getUser())?->getUid()` instead of a column
read — a `locked` or `replaced` card is naturally excluded (not `active`), so a locked card simply
stops working at the door without anyone needing to remember to also touch the credential sync.
`AccessEvent::$cardUser` resolution (denied/granted card taps, § door-access-spec.md's Access event
log) now goes through `AccessCardRepository::findOneByUid()` instead of `UserRepository`, matching
regardless of the card's current status — a **locked** card that gets tapped and denied still
correctly identifies who it belongs to in the access log, which is the entire point of locking
rather than deleting: the "bad actor" stays traceable.

## Hardware note

Not designed in detail here — this stays a server/API/UI spec, same split as
door-access-spec.md/door-access-firmware-spec.md. When built, expect:

- A small networked (WiFi is fine — this sits at a staffed desk, not on the door's dedicated
  ethernet drop) microcontroller + NFC reader + small display, no relay, no door-position sensor.
- Reuse the door firmware's exact UID-capture/formatting approach (`card_reader.cpp`'s
  `formatUid()`: uppercase hex, no separators) so a UID read by either device is byte-for-byte the
  same string.
- A companion `card-station-firmware-spec.md`, living in its own PlatformIO project the same way
  `door-access-firmware-spec.md` lives in `/Documents/PlatformIO/Projects/y-wal/spec.md`.

## Open items

- **"Find by card" `not_found` doesn't offer to create a new contact** — an unrecognised tap during
  a lookup could plausibly one-click into "+ New person" with the scanned UID prefilled, so an
  unregistered card becomes a new contact and a linked card in one step. Considered and explicitly
  deferred — the lookup flow here only ever finds existing members.
- **Reassigning a card already registered to someone else** (the `conflict` follow-up) stays a
  plain, separate confirmed admin action — mark the old owner's active row `replaced`
  (not silently deleted — its history stays queryable) and create a fresh active row for the new
  owner — not something the scan flow itself does automatically.
- **The "only one pending session" and "only one active card per user" rules are application-level
  invariants, not database ones** — acceptable for a single staffed station operated by one person
  at a time; would need real locking (a transaction + `SELECT ... FOR UPDATE`, or a unique
  constraint on `(user_id)` filtered to `status='active'`/`'pending'` if the DB ever supports
  partial indexes) if concurrent use becomes real.
- **Multiple stations** — add a `station_id` column to both tables and thread it through the API
  once a second desk exists; not designed here.
- **Self-service verification** ("is this my card?" without staff involved) is a deliberate
  non-goal — see § Privacy. Worth reconsidering only behind a member's own login, never as a
  station-floor kiosk mode.
- **No background expiry job for a stale `pending` session** — an abandoned arm (browser tab closed
  without the modal's cleanup firing) sits at `pending` past its `expires_at` until something else
  cancels or supersedes it. `expires_at` exists so a lazy check *could* be added (e.g. the "only one
  pending" cancel-first step also treating a past-due `pending` row as already expired) — not built
  as a real background job here.
- **Full lock/unlock *history*** (more than the single most-recent lock/unlock pair of timestamps)
  isn't kept — if a card is locked and unlocked multiple times, only the latest cycle's
  `locked_at`/`locked_by_id`/`unlocked_at`/`unlocked_by_id` survive. A genuine multi-event audit log
  would need its own append-only table (mirroring `access_event`'s own shape) — not proposed here
  since a manual, rare, staff-driven action doesn't obviously need it yet.
