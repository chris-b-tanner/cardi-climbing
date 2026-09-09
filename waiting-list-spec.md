# Waiting list spec (brief) — not yet implemented

## Goal

When an event occurrence is full, let members join a waiting list instead of being blocked, and get the next person in efficiently when a spot frees up.

## Status model

- `Attendee::STATUS_WAITING` already exists. FIFO by `createdAt` — no priority field.
- Waiting attendees must not count toward `maxAttendees` (see open items — the capacity-check queries don't yet know this).

## Free / open events

- Self-serve "book now": when full, offer "Join waiting list" instead of blocking.
- Creates an `Attendee` with `status = waiting` directly — no payment, no credit spend, same eligibility checks as a normal booking minus the capacity gate.
- `BookingService::createBooking()` needs an opt-in path (flag or separate method) that skips the maxAttendees rejection and sets `waiting` instead of `confirmed`.

## Ticketed events

- Don't charge upfront. "Join waiting list" creates the same `waiting` `Attendee`, no `SalesOrderRow` — works the same as the free case, just also offered on ticket-gated events.
- When a confirmed seat frees up (cancellation), pick the next waiting attendee (FIFO) and send a claim email:
  - Reuse `MagicLinkService` — link logs them in and drops them straight onto checkout for that ticket/occurrence.
  - Time-limited claim window (e.g. 24–48h). Either the magic link's own `expiresAt`, or a separate `claimExpiresAt` on `Attendee` if the link should outlive the claim window for other reasons.
  - If they don't complete purchase in time: mark the entry expired/skipped, notify the next person in line.
- Needs a trigger (cancellation event, or a scheduled sweep) to: detect a freed slot → pick next waiting attendee → send claim email → track the deadline → expire and cascade if missed.

## Open items / decisions needed before building

- Exclude `waiting` (and confirm `pending`'s status) from `AttendeeRepository::countActiveForOccurrence()` / `findActiveForEventsInRange()` — currently only `cancelled` is excluded. Required first, or capacity math breaks.
- What happens to waiting entries if `maxAttendees` increases, or the event is cancelled/deleted outright?
- Claim-expiry sweep: cron job, or lazy check on next relevant page load?
- Admin-initiated promotion: the event view page's "Waiting" tab exists already — probably wants a manual "Promote to confirmed" action per row, for phone/in-person bookings an admin manages directly.
- Notification copy/timing (how many reminders before expiry, if any).
