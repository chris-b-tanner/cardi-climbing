# Safety checks spec — not yet implemented

## Summary for the safety inspector

*This section describes what the proposed system will do. It is a proposal: nothing below is in use yet, and the technical detail that follows it is for our developers.*

We plan to add safety checks to the system the team already uses every day to run the centre. The aim is that no check is missed, and that there is a complete, permanent record of every check carried out.

**A register of everything that needs checking.** The centre will keep one register of everything it owns and uses, and any item on it can be set up for safety checks. That includes climbing equipment such as auto-belays and mats, PPE, the building, the fire alarm and extinguishers, electrics and first aid kits. Each entry records what the item is, where it is, and its serial or asset number where it has one. The safety page lists every item that has checks set up.

**Each check has its own frequency.** One item often needs several checks at different intervals. For example, an auto-belay might have a daily visual check by staff, a monthly operator inspection and an annual service by the manufacturer. Each check is set up with how often it is due, written instructions for what to check, and whether it is done by our own staff or by an outside contractor.

**Staff are reminded before a check is due.** As a check comes due, it appears on the team's shared task list, assigned to a named person. Checks show up in good time: about a week ahead for a monthly check, and a month ahead for an annual one or anything that needs a contractor booked. Daily checks appear on a "today" list. A safety page shows at a glance anything overdue, due today, due soon, or out of service.

**Every check is recorded, and records cannot be changed.** When someone carries out a check, they record:
- the date and time
- who did it, or the contractor's name for an external inspection
- the result: **pass**, **pass with advisory** (safe, but something to keep an eye on), or **fail**
- comments, which are required for anything other than a pass
- optionally, a photo or a copy of the contractor's certificate

Once saved, a record cannot be edited or deleted by anyone. If a mistake is made, it is corrected by adding a further entry, so the original remains visible.

**A failed check takes the item out of use.** If a check fails, the item is immediately marked **out of service**, and a follow-up task is created for the problem to be dealt with. It stays out of service until a passing check has been recorded and someone deliberately returns it to use. A passing daily check does not automatically clear a failed annual inspection.

**Records can be produced on request.** We will be able to print or export the full inspection history for any item, type of equipment or date range, for an inspector or insurer.

**Points we have not yet decided**, on which your advice would be welcome:
- Whether the next due date should be counted from when a check was actually done, or kept to a fixed annual date. For example, if an annual service is done three weeks late, should the next one be due a year from the original date or a year from when it was done?
- Whether anyone should be alerted automatically when a check becomes overdue.
- Whether unsupervised members should be prevented from entering the building while key equipment (such as an auto-belay) is out of service or overdue for a check.
- Whether each check should be a tickable list of individual points rather than a single overall result.
- Whether to track an end-of-life date for equipment with a limited lifespan, such as ropes, harnesses and auto-belay lanyards.

## Goal

Let any item in the asset register (`assets-spec.md`) be set up for periodic safety checks, from an auto-belay to the building's fire alarm. When a check is coming due, surface it as a task someone can pick up. Whoever does the check records the result, which keeps a permanent inspection log for insurers, HSE or a manufacturer audit.

## Approach in one paragraph

The **item** being checked is an `Asset` from the asset register (`assets-spec.md`); there's no separate safety item. An asset becomes "safety checked" simply by having one or more check schedules. The **schedule** and the **record** of each check get their own entities. The **task** that prompts someone to do the check is an ordinary pinned `Note`. Notes already give us pinning, assignment (with the `NoteAssignmentMailer` email), the shared task list at `/admin/actions`, @mentions and the notes panel, so none of that needs rebuilding. A note can't be the record itself, though. Notes can be deleted, they have no structured outcome, and an inspection log has to be permanent. So the note is the to-do, and a `SafetyCheckRecord` is the proof that it was done.

## Concepts

- **Item**: an `Asset` with at least one active schedule. Examples: "Auto-belay 3 (TruBlue, s/n 12345)", "Fire alarm system", "First aid kit, reception", "Bouldering mats". Things that aren't bought kit, such as the building or the fire alarm system, are still entered as assets, with no cost.
- **Schedule**: one kind of check on an item and how often it's due. One item often needs several. An auto-belay might need a daily visual check, a monthly operator inspection and an annual manufacturer service. A fire system might need a weekly alarm test, a six-monthly service and annual extinguisher checks. Each schedule has its own interval, instructions and due date.
- **Record**: one check actually done against a schedule, with its outcome. Records can't be edited or deleted after they're saved.
- **Task**: a pinned `Note` on the asset, created automatically when a schedule comes due.

## Data model

### Changes to `Asset` (from `assets-spec.md`)

There's no `SafetyItem`. The asset register provides name, category, location, identifier,
description, status and history. This spec adds:

| Field | Type | Notes |
|---|---|---|
| `outOfServiceSince` | ?datetime | Set when the status becomes `out_of_service`, whether from a failed check or by hand. Cleared on return to service. |

and extends the asset `category` list with `building`, `fire_safety`, `electrical` and
`first_aid`, for things that are checked but not bought kit.

Status, using the asset register's values:

- `in_use` and `in_storage` both count as **in service**: checks keep coming due, so spare ropes in
  the store stay checked.
- `out_of_service`: can't be used. Checks still show as due (they're often how it gets returned
  to service), but the sweep doesn't create new task notes for it.
- `disposed`: the asset's schedules are deactivated automatically, any open task notes are
  completed by the system with "Asset disposed", and it drops out of every safety list. Its
  inspection history is kept.

A **batch** asset (e.g. "Hire harnesses, 20") is checked as a whole: one record covers the batch.
If one harness fails, the usual fix is to partly dispose of it from the batch (see
`assets-spec.md`) and record the check as a pass with an advisory, rather than failing the whole
batch. Anything that needs its own check history should be tracked individually.

### `SafetyCheckSchedule`

| Field | Type | Notes |
|---|---|---|
| `asset` | ManyToOne `Asset` | Cascade remove isn't needed, because assets are disposed of, never deleted |
| `name` | string(150) | "Daily visual check", "Annual LOLER inspection" |
| `instructions` | ?text | What to check. Shown on the record form. A plain-text checklist is fine for v1. |
| `intervalCount` + `intervalUnit` | int + `day`/`week`/`month`/`year` | Kept as calendar units rather than a number of days, so "every 6 months" lands on the same day of the month |
| `leadDays` | ?int | How early the task appears. Null means use the default (see below). |
| `defaultAssignee` | ?User | Who the task goes to on creation. Null leaves it unassigned for anyone to pick up. |
| `external` | bool | Done by a contractor (fire alarm service, LOLER). Changes the record form; see below. |
| `nextDueAt` | date | Stored rather than worked out each time, so the "due soon" query stays a simple `WHERE` |
| `lastCheckedAt` | ?datetime | Stored for the same reason |
| `openTask` | ?ManyToOne `Note`, `onDelete: SET NULL` | The currently open task note, if there is one |
| `active` | bool | Lets a schedule be paused without deleting it |

### `SafetyCheckRecord` (permanent)

| Field | Type | Notes |
|---|---|---|
| `schedule` | ManyToOne | |
| `asset` | ManyToOne `Asset` | Copied from the schedule so the asset's log needs no join |
| `performedAt` | datetime | Defaults to now. Can be backdated, but not set in the future. |
| `performedBy` | ?User | The team member who did the check, or who logged a contractor's visit |
| `contractorName` | ?string | For `external` schedules |
| `outcome` | string | `pass` / `advisory` (passed, but something to watch) / `fail` |
| `comments` | ?text | Required unless the outcome is `pass` |
| `evidenceKey` | ?string | S3 key for a certificate or photo. Stored privately and downloaded through presigned URLs, the same way as `UserFileUploader`. |
| `taskNote` | ?ManyToOne `Note` | The task this record closed, if there was one |
| `createdAt` | | |

There's no edit or delete route for records. A mistake is fixed by adding a note on the asset. If it really matters, an owner can add a correcting record.

## Note integration

- No new noteable type. Task and follow-up notes go on the asset, using `Note::TYPE_ASSET` from
  `assets-spec.md`, so they appear in the asset's notes panel alongside its other history.
- **Task creation.** The sweep (below) creates the note with:
  - Content `"Safety check due {date}: {schedule name}"`, followed by the instructions.
  - `addedBy = null`. This is how system notes already look, and it means only an owner can delete one.
  - Pinned, and `assignedTo` set to the schedule's `defaultAssignee`, which sends the existing assignment email.
  - Saved to `schedule.openTask`.
- **A task can only be closed by recording a check.** Clicking "Complete" on its own would make a check look done without saying how it went. So for a note that is some schedule's `openTask`:
  - The Actions card and the notes panel show **Record check** in place of Complete. It links to the record form.
  - `AdminNoteController::complete()` and `pin()` refuse it with a flash message, the same way they already refuse notes that record a sent email.
- **Recording a check** (one transaction):
  1. Save the `SafetyCheckRecord`.
  2. Run `$note->complete($user)` on the open task, if there is one.
  3. Set `lastCheckedAt`, set `nextDueAt` to `performedAt` plus the interval, and clear `openTask`.
  4. On `fail`: set the asset to `out_of_service` and pin a new note on the asset, "Failed: {schedule name} — {comments}", for follow-up. A failed check still counts as a check done, so the schedule moves on as normal. What stops the item being used is being out of service.
  5. On `pass` when the asset is out of service: offer a **Return to service** checkbox (back to `in_use`), ticked by default. Don't return it automatically, because a passed daily check doesn't clear a failed annual inspection.
- Recording a check before it comes due is allowed from the asset page, with no task needed. If an open task exists it gets closed as well.
- A deleted task note is handled by `onDelete: SET NULL` on `openTask`, and the next sweep creates a new one if the check is still due.

## When things surface ("due soon")

A schedule is **due soon** once `today >= nextDueAt - leadDays` and **overdue** once `today > nextDueAt`.

If `leadDays` is null, the default is about a quarter of the interval, capped at 30 days:

| Interval | Default lead |
|---|---|
| ≤ 1 day | 0 (shows on the day) |
| 1 week | 2 days |
| 1 month | 7 days |
| 3 months | 14 days |
| 6 months and over | 30 days |

Contractor checks (`external`) probably want a longer lead, because a visit has to be booked. Their default could be 30 days whatever the interval.

### Frequent checks don't create notes

A daily or per-session check (a pre-session wall walk, the auto-belay daily checks) would create a pinned note every day. That would flood `/admin/actions` and each item's notes trail. Proposal: schedules with an interval **under 7 days** never create a task note. They appear only on the Safety page's **Today** list, where a single **Done ✓** button records a `pass` straight away. "Report a problem" opens the full form. Everything else behaves the same: the record, `nextDueAt`, and a failed check taking the item out of service.

### The sweep

`app:safety-checks:sweep` would be the first console command in `src/Command`. It finds active schedules on in-service assets (`in_use` or `in_storage`) that are due soon, have no `openTask`, and have an interval of 7 days or more, then creates the task notes. It is safe to run more than once.

Run it from a daily **Heroku Scheduler** job, alongside the existing `messenger:consume bulk_email` job, and add it to the readme. To cover a missed run, also call it when someone opens `/admin/safety`. It's cheap, so running it again is harmless.

## Admin UI

- **Nav:** a "Safety" link in `admin/base.html.twig` next to Actions, with a badge counting overdue checks plus out-of-service items.
- **`/admin/safety`** (dashboard), with sections in this order:
  1. **Out of service.** Red. Safety-checked assets that can't be used.
  2. **Overdue.**
  3. **Today.** Frequent checks due today, each with a one-click Done.
  4. **Due soon.** Shows the assignee and the due date.
  5. **All checked items.** Every asset with an active schedule. Filter by category, location and status; search by name or identifier.
- **Asset page** (from `assets-spec.md`) gains a **Safety checks** section: the schedules with their next due dates and a **Record check** button for each, **Add schedule** (admins), and the full inspection log (newest first, outcome badge, evidence link). The asset page's existing status controls and notes panel cover taking it out of service and the task notes.
- **Asset register:** a "Safety checked" filter, and a badge on assets that have schedules.
- **Record-check form:** the schedule's instructions are shown read-only, then outcome, comments, performed at, evidence upload, and contractor name for external schedules.
- **Inspection log export:** a print view and a CSV of records, filterable by asset, category and date range. This is what gets handed to an insurer or inspector.

## Permissions

- **ROLE_TEAM:** view everything, record checks, take an asset out of service.
- **ROLE_ADMIN:** create and edit schedules, return an asset to service without a passing check.
  Adding and disposing of the assets themselves follows `assets-spec.md`.
- For a safety-checked asset that's out of service, the asset register's ordinary status change
  back to `in_use` is admin-only, so a team member can't clear a failed check by hand.
- **ROLE_OWNER:** add a correcting record (see above).

## Open questions

1. **How the next due date is worked out.** This spec counts from when the check was actually done, so an annual service done 3 weeks late pushes every later one 3 weeks back. Some statutory checks should stick to the original anniversary instead. Should there be a per-schedule option (`fromCompletion` or `fixed`), or is counting from completion enough for v1?
2. **Overdue escalation.** Should the sweep email someone (admins, or the schedule's default assignee) once a check is overdue, or is the nav badge enough?
3. **Link to door self-access.** Should a self-access session be blocked, or come with a warning, when a critical item (for example an auto-belay used in self-access) is out of service or overdue? That would need a `critical` flag on the asset. See `door-access-spec.md`.
4. **Structured checklists.** Should instructions become tickable checklist lines, each saved on the record? This spec uses free text plus an overall outcome for v1.
5. **Item lifespan.** Should assets get an end-of-life date for kit with a fixed life (ropes, harnesses, auto-belay lanyards), with a reminder when it's close? It would live on `Asset`, so it's useful beyond safety.
6. **Seed data.** Do we want a starter set of assets and schedules (fire alarm weekly test, extinguishers annual, emergency lighting monthly and annual, first aid kit monthly, and so on) loaded by a migration or fixture, or entered by hand?

## Build order

Needs step 1 of `assets-spec.md` (the `Asset` entity, asset page and `TYPE_ASSET`) first.

1. Schedule and record entities, the `Asset` changes, and the migration.
2. Schedule admin on the asset page (create, edit, pause).
3. Record-check form and the inspection log on the asset page.
4. The sweep command and the task-note behaviour, including blocking Complete and pin on task notes.
5. The `/admin/safety` dashboard, including the Today list and the nav badge.
6. Log export.
