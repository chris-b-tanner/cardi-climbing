# Card access at Y Wal — how it works

*Draft for on-site volunteers and the team. Plain-language notes on how members get in and out of
the wall with their Y Wal card, what gets recorded, how cards are issued and managed, and what to
check when a card doesn't work. The UAT checklist at the end is built from the same rules. (The
technical detail lives in `door-access-spec.md` and `card-setup.md`.)*

---

## The short version

- Members **tap their Y Wal card** on the reader **outside** the door to get in during a
  **self-access session** they've booked.
- Anyone leaving can **tap their card** on the reader **inside** the door to get out, which also
  **checks them out**. The push button opens the door too, but doesn't record anything.
- During **staffed sessions**, volunteers check people in at reception as usual. The outside
  reader doesn't let people in just because they have a staffed-session booking.
- There are no door PINs or keypad any more. It's cards only.

---

## The two card readers

### Outside reader (getting in)

Opens the door if the card belongs to **either**:

1. A member with a **confirmed booking** on a **self-access session** that's happening now. The
   card works from **5 minutes before** the session starts until **10 minutes after** it ends.
   It works as many times as they like in that window, so stepping out and back in is fine.
2. Someone with an **all-hours card**, a small number of cards (e.g. caretaker) set up by an
   admin. These open the door at any time, with no booking needed.

| What you hear | What it means |
|---|---|
| Quick **double high beep** | Door unlocked — come in. |
| Single **low beep** | Not allowed in right now (see *When a card doesn't work* below). |
| **No beep at all** | After 5 refused taps in a row, the reader ignores all cards for about 45 seconds. Wait and try again. |

### Inside reader (getting out)

- Tapping a card opens the door and records that the member has left.
- It's not a security check. Any card that's been tapped at the door in the last year (and isn't
  locked) will open it from inside, booking or not.
- A card it doesn't recognise is **ignored silently** (no beep, no door). The usual reason is a
  brand-new card that's never been tapped at the outside reader yet. Use the push button; the card
  will work on the inside reader after its first tap outside, even if that tap was refused.

### Several people through one door opening

When a group arrives (or some are leaving while others arrive), the door only needs to open once,
but **everyone should still tap their own card**, even if the door is already open:

- The first valid tap unlocks the door. A tap while the door is standing open doesn't need to
  unlock it, but is still recorded for that person. A tap while it's still shut unlocks it again.
- Each person tapping in is checked in to **their own** booking. Anyone tapping the inside reader
  in the same opening is checked out. The Access log shows a separate line for each person, all
  with the same door-open and door-closed times.
- Anyone who just walks through behind someone without tapping **isn't checked in**.
- Check-ins appear once the door has **closed** again.

> **Needs the door firmware update (October 2026).** Older door firmware only tracked one tap per
> opening. On that firmware, when a second person tapped while the door was open, neither got
> checked in (both still got through). If D7–D9 in the UAT checklist fail, check the door has the
> new firmware.

---

## Check-in and check-out — what gets recorded

Every booking has a **Checked in** and a **Checked out** time. You can see them in the
**Attendance** box on the booking's page in the admin.

### Checked in

- **Self-access session:** recorded automatically the first time the member comes in with their
  card. Shown as *"via door card"*.
- **Reception:** a volunteer presses **Check in now** on the booking. Adding someone to a session
  from the admin while it's running (or within 15 minutes of starting) also checks them in. Shown
  as *"by [volunteer's name]"*.
- The check-in time is set **once**, at their first entry, and doesn't change if they come and go.

### Checked out

- Recorded when the member **taps their card on the inside reader**.
- **Stepping out and coming back is fine.** Each time they tap out during their session, the
  checked-out time moves to that latest exit. So it ends up as the last time they left.
- **Staying late is fine too.** If someone is still in after their session ends, tapping out
  still checks them out at the time they actually left. This works for reception check-ins as
  well as card check-ins, as long as it's within 24 hours of checking in.
- **Leaving with the push button records nothing.** The booking will just show *"Not yet"*
  checked out.

### Two sessions in one day

A member can do a staffed session in the day and a separate self-access session in the evening.
The two bookings are kept apart:

- Their evening card entry checks them into the **evening** booking only.
- Taps on the inside reader during the evening session only check them out of the **evening**
  booking. That's true even if they never tapped out of the daytime session.
- The daytime booking only gets a check-out time if they tap out during the day, or straight after
  it as an overstay. It never gets one later in the evening.

### Things that won't be recorded (known limits)

- **Following someone else in** without tapping: they won't be checked in. Tapping out later won't
  check them out of any other booking either.
- **Leaving, coming back, and then staying past the end of the session:** their last exit (after
  the session has finished) isn't recorded. The checked-out time stays at the exit before that.
  This only affects people who left and came back.

---

## When a card doesn't work at the outside reader

Work through these in order:

1. **Is the session set to self-access?** On the event, the **Self access (door card)** box must
   be ticked. If it isn't, the card won't open the door for anyone booked on it. This is the most
   common cause. (On a booking's page, the Attendance box shows a **"Door access valid"** line only
   when the event is self-access.)
2. **Is the booking confirmed?** Pending, waiting-list and cancelled bookings don't work at the
   door.
3. **Is it the right time?** The card only works from 5 minutes before the start until 10 minutes
   after the end, on the booked date.
4. **Does the member have an active card?** Check the card on their profile. A **locked** card
   never opens the door. If they've had a replacement card, only the newest one works.
5. **Was anything just changed?** The door picks up changes (new bookings, ticking self-access,
   unlocking a card) about every **30 seconds**. Wait half a minute and try again.
6. **Too many failed taps?** The reader ignores cards for about 45 seconds after 5 refused taps
   in a row.

If none of that explains it, look at the **Access log** (below), then pass it on to an admin with
the member's name and the time they tried.

### If the internet is down

The door keeps working with the last list of bookings it had, and saves its records to upload
when it's back online. Anything changed while it's offline won't reach the door until the
connection returns. That includes new bookings, ticking self-access and unlocking cards.

---

## Managing cards

### Who can do what

| Action | Who | Where |
|---|---|---|
| **Find by card** (tap a card to see whose it is) | Any team member | People list → **Find by card** |
| Link a card (first card or replacement) | Admins | Member's page → **Scan card** |
| Verify a card (is this card theirs?) | Admins | Member's page → **Verify** on the card |
| Lock / Unlock a card | Admins | Member's page → **Lock** / **Unlock** |
| Remove a card | Admins | Member's page → **Remove** |
| Grant / revoke all-hours access | Admins | Settings → Cards → the card → **Grant** / **Revoke** |
| See every card and its history | Admins | Settings → Cards |

Every card action (added, replaced, locked, unlocked, removed, all-hours granted/revoked) is
written to the member's **notes** with who did it and when.

### The card station (reception reader)

Cards are linked, verified and looked up using the **card station**, a small reader at the
reception desk. It's separate from the door readers and never opens anything.

- **Blue light (steady):** connected and ready.
- **Red light (steady), with a beep every 10 seconds:** no WiFi or can't reach the server. Scans
  won't work until it's blue again.
- **Short beep and green flash** when you tap a card: the card has been read.
- Results only appear on the admin computer, in the pop-up you started the scan from. The station
  itself never shows whose card it is, so it's fine to use where members can see it.

#### Connecting the card station to WiFi

The station joins the building's WiFi by itself once it's been told the network name and password.
Do this when it's first set up, or whenever the WiFi network or password changes.

1. **Power the station on.** The light is red until it's connected.
2. On a phone or laptop, open the WiFi settings and join the network **`ywal-reader-setup`**. The
   password is **`ywalreader`**.
3. A setup page should open by itself (like a café WiFi login page). If it doesn't, open a browser
   and go to **http://192.168.4.1**.
4. The page shows whether the station is connected, and to which network. Enter:
   - **SSID:** the WiFi network's name, exactly as spelt (it's case-sensitive).
   - **Password:** the WiFi password. If you're only correcting the name, leave this blank to keep
     the saved password.
5. Press **Save & restart**. The station gives **two short beeps** and restarts.
6. Reconnect your phone or laptop to its normal WiFi.
7. When the station connects it plays a **short tune**. The light turns **blue** once it can also
   reach the Y Wal server. That can take up to about 15 seconds after the tune.

The `ywal-reader-setup` network is **always on**, even when the station is working. That way the
WiFi can be changed at any time without needing a technician. It also means anyone nearby who
knows the password could change it, so keep the password to the team.

**If it won't connect:**

- **Red light and a low beep every 10 seconds:** it isn't on WiFi. Check the network name and
  password and save again. The station only works with **2.4 GHz** WiFi. If the router has
  separate 2.4 GHz and 5 GHz networks, use the 2.4 GHz one.
- It **can't** use WiFi that needs a sign-in page (e.g. some guest WiFi) or a username as well as
  a password (e.g. eduroam-style networks). It needs an ordinary password-only network.
- **Played the tune but the light stays red:** it's on the WiFi, but can't reach the internet or
  the Y Wal server. Check the internet connection itself, or whether that network blocks devices
  from going online.
- Saved settings survive power cuts and unplugging. You only need to do this again if the WiFi
  network or password changes.

How a scan works, for every action below:

1. Press the button in the admin (e.g. **Scan card**). A pop-up says *"Waiting for a tap on the
   card station…"*.
2. Tap the card on the station **within 60 seconds**.
3. The pop-up shows the result. If nothing is tapped in time it says *"Timed out — try again."*
4. Only **one scan can be waiting at a time**. Starting a new one (on any computer) cancels any
   other scan still waiting. Closing the pop-up cancels yours.

### Giving a member their first card

1. Open the member's page in the admin. With no card yet, there's a dashed placeholder with a
   **Scan card** button.
2. Press **Scan card**, then tap the new card on the station.
3. The pop-up says *"Card linked to [name]."* and the page shows the card with its number.
4. The card works at the door from the next door update (about 30 seconds), for any self-access
   sessions they're booked on.

If the pop-up says *"That card is already registered to another member. Nothing changed."*, that
card has been used before (see *Re-using a card* below). Use a different card.

### Lost or stolen card — issuing a replacement

1. On the member's page, press **Lock** straight away. The lost card stops opening the door from
   the next door update (about 30 seconds). If anyone tries it, the Access log still shows whose
   card it was.
2. When you have a new card for them, press **Scan card** and tap the new card on the station.
3. The new card becomes their card. The old one is marked **Replaced** for good; it can never open
   the door again, even if it turns up later.
4. **All-hours access is not carried over.** If the old card had it, an admin needs to grant it
   again on the new card (Settings → Cards).

You can also skip step 1 and just scan the new card; the old one is replaced either way. Locking
first is still best if there could be a wait before the new card is ready.

### Card found again, or locked by mistake

- If it's **still locked** (no new card linked yet): press **Unlock** on the member's page. It
  works at the door again from the next door update.
- If a **replacement has already been linked**, the old card can't be brought back. Keep using the
  new one.

### Locking a card for other reasons

Locking stops a card opening the door without taking it off the member, e.g. while something is
sorted out. The card shows greyed out with *"· Locked"*. The member can still be checked in at
reception, and their bookings are unaffected; only the card stops working at the door.

**Unlock** puts it back to normal.

### Removing a card

**Remove** takes the card off the member completely, with no replacement, e.g. when they leave
and hand it back. They'll have no card until a new one is linked. A removed card can't be linked
to anyone again (see below).

### Re-using a card

A card can only ever belong to **one member, once**. A card that has been replaced or removed
can't be linked to anyone again, not even the same member. Linking it gives *"That card is already
registered to another member"*. Returned cards can't currently be re-issued; use a new card.

### Checking a card belongs to the right person (Verify)

1. On the member's page, press **Verify** on their card, then tap the card they're holding.
2. The pop-up says one of:
   - *"✓ This card belongs to [name]."*
   - *"✗ This card is registered to [someone else], not [name]. Nothing changed."*
   - *"✗ This card isn't registered to anyone. Nothing changed."*

Verify never changes anything.

### Finding a member from their card (Find by card)

1. On the People list, press **Find by card** and tap the card on the station.
2. If the card has ever been registered to someone, the admin goes straight to that member's page.
   This includes old, locked or replaced cards, so check the card's status on their page.
3. Otherwise it says *"That card isn't registered to anyone."*

### All-hours cards

For the few people who need to get in at any time without a booking (e.g. caretaker):

1. Settings → Cards → click the card number → **Grant** all-hours access.
2. The card opens the door at any time from the next door update. **Revoke** undoes it.
3. It only works while the card is active. Locking the card stops it too.
4. It belongs to the **card**, not the person. A replacement card needs it granting again.

### The Cards report (Settings → Cards)

Lists every card the station has read: its number, owner, status (**Active**, **Locked**,
**Replaced**, **Removed**, or **unregistered** for a card that's been scanned but never linked),
how many times it's been scanned and when it was last seen. Click a card number for its full
history.

---

## Where to look in the admin

- **A booking's page → Attendance box:** checked-in and checked-out times, how each happened, and
  (for self-access sessions) the time window the card works. **Check in now** is here too.
- **Settings → Access log:** every tap at the door, newest first. The **Type** column shows
  **Denied** for a refused tap. Card entries, exits and all-hours entries all currently show as
  **Attendee**. The **Who** column is where to look:
  - **Member's name — Event title:** a card entry or exit linked to that booking.
  - **"(card, no active booking)":** the card was recognised as that member's, but the tap isn't
    linked to a booking. That's normal for an all-hours card, or for a tap on the inside reader
    when there was no session to check them out of. With a reason in brackets after it, it was a
    refused entry.
  - **"(expired)":** they do have a self-access booking, but it's outside its time window.
  - **"(not_found)":** the door had no booking or all-hours access for that card at all.
    Usually the event isn't ticked as self-access, the booking isn't confirmed, or the card is
    locked.
  - **"Unregistered card":** a card that isn't linked to anyone.
- **Settings → Cards:** every card and its status. See *The Cards report* above.
- **Settings → Door log:** technical messages from the door controller itself. Mostly for admins.

---

## Quick reference

| Situation | What happens |
|---|---|
| Booked on a self-access session, taps outside during the session | Door opens; checked in (first time only) |
| Booked on a staffed session only, taps outside | Refused. Check in at reception instead |
| All-hours card, taps outside | Door opens at any time (no booking involved) |
| Taps the inside reader | Door opens; checked out of the current session (time moves to the latest exit) |
| Leaves with the push button | Door opens; nothing recorded |
| Stays past the end of their session, then taps out | Checked out at the time they actually left |
| Brand-new card, never used at the door, taps inside | Ignored. Use the push button |
| Event's self-access box not ticked | Nobody's card works for that session |

---

## UAT checklist

Run these with at least two test members (**Member A** and **Member B**), a few spare cards, an
admin login and a team (non-admin) login. Remember the door takes up to about **30 seconds** to
pick up any change; wait that long before each door test.

### Card station and linking

| # | Steps | Expected |
|---|---|---|
| C1 | Unplug the station's network / turn off WiFi. | Light goes red; it beeps every 10 seconds. Plug back in: light goes blue. |
| C1a | Join `ywal-reader-setup` (password `ywalreader`) from a phone. | The setup page opens by itself (or at http://192.168.4.1) and shows the station's current network. |
| C1b | On the setup page, enter a wrong WiFi password, **Save & restart**. | Two beeps, restart; light stays red with a beep every 10 s. |
| C1c | Enter the correct WiFi details, **Save & restart**. | Two beeps, restart, a short tune when connected, then the light turns blue. Scanning works (e.g. C2). |
| C1d | Unplug the station's power and plug it back in. | Reconnects on its own (tune, then blue) without re-entering the WiFi details. |
| C2 | Member A has no card. Admin: **Scan card**, tap a new card. | *"Card linked to Member A."* Card shows on their page. Note added to their record. |
| C3 | **Scan card** for Member B, tap Member A's card. | *"That card is already registered to another member. Nothing changed."* |
| C4 | **Scan card**, then don't tap for 60 seconds. | *"Timed out — try again."* |
| C5 | Start **Scan card** for Member A, then (other tab) start one for Member B and tap a card. | Card links to Member B only; Member A's pop-up says *"Timed out — try again."* |
| C6 | Log in as a team (non-admin) member and open Member A's page. | Card is shown, but no Scan card / Verify / Lock / Remove buttons. |

### Verify and Find by card

| # | Steps | Expected |
|---|---|---|
| V1 | Member A's page: **Verify**, tap Member A's card. | *"✓ This card belongs to Member A."* |
| V2 | Member A's page: **Verify**, tap Member B's card. | *"✗ This card is registered to Member B, not Member A. Nothing changed."* |
| V3 | Member A's page: **Verify**, tap an unused card. | *"✗ This card isn't registered to anyone. Nothing changed."* |
| V4 | As a team member: People → **Find by card**, tap Member A's card. | Goes to Member A's page. |
| V5 | **Find by card**, tap an unused card. | *"That card isn't registered to anyone."* |

### Lock, lost card and replacement

| # | Steps | Expected |
|---|---|---|
| L1 | Member A (booked on a running self-access session): **Lock**. Tap at the door. | Card shows *"· Locked"*. Door refuses (low beep). Access log shows Member A as denied. |
| L2 | **Unlock**, wait 30 s, tap at the door. | Door opens. |
| L3 | **Lock** Member A's card, then **Scan card** a new card. | New card linked; page loads normally, showing the new card. Old card is **Replaced** in Settings → Cards. Note says *"Access card replaced: old → new"*. |
| L4 | Tap the old (replaced) card at the door. | Refused. |
| L5 | Try to link the old (replaced) card to anyone, Member A included. | *"That card is already registered to another member."* |
| L6 | Without locking, **Scan card** a further new card for Member A. | Old card becomes **Replaced**; new one works at the door. |

### Remove

| # | Steps | Expected |
|---|---|---|
| R1 | Member B: **Remove** (confirm). | Card gone from their page; **Removed** in Settings → Cards; note added. |
| R2 | Tap that card at the door. | Refused. |

### All-hours cards

| # | Steps | Expected |
|---|---|---|
| H1 | Settings → Cards → Member A's card → **Grant**. Tap at the door with **no** booking. | Door opens. |
| H2 | **Lock** the card, tap at the door. | Refused. **Unlock**: opens again. |
| H3 | Replace Member A's card with a new one, tap the new card with no booking. | Refused, until all-hours is granted on the new card. |
| H4 | **Revoke**, tap with no booking. | Refused. |

### Door entry (self-access)

| # | Steps | Expected |
|---|---|---|
| D1 | Member A, confirmed booking on a self-access session running now. Tap outside. | Double beep, door opens. Booking shows checked in *"via door card"*. |
| D2 | Same, but the event's **Self access** box is unticked. | Refused; Access log shows *"(card, no active booking) (not_found)"*. |
| D3 | Self-access booking that starts in over 5 minutes, or ended more than 10 minutes ago. | Refused. The Access log shows *expired* if the session starts within the next 2 hours, otherwise *not_found*. |
| D4 | Booking set to **Pending** or **Cancelled**. | Refused. |
| D5 | Tick **Self access** on an event that already has bookings; wait 30 s; tap. | Door opens. |
| D6 | Tap a refused card 5 times in a row, then a valid card. | After the 5th, no beep at all for about 45 s; then the valid card works. |
| D7 | Members A and B both booked on a running self-access session. A taps in; while the door is open, B taps too; both go in; door closes. | One unlock. Both bookings checked in at the time the door closed; two lines in the Access log with the same door-open/closed times. |
| D8 | A and B both tap, one straight after the other, **before** the door is opened. Both go in. | As D7: both checked in. |
| D9 | A taps in; while the door is open, Member C (already inside) taps the inside reader and leaves. | A checked in; C checked out at their tap time; separate Access log lines. |
| D10 | A taps but doesn't open the door; after about 6 seconds (lock has re-engaged) B taps; both go in. | B's tap unlocks the door again; both bookings checked in. |

### Exit and check-out

| # | Steps | Expected |
|---|---|---|
| X1 | After D1, tap the inside reader. | Door opens; booking shows checked out at that time *"via exit card"*. |
| X2 | Come back in by card, then tap out again during the session. | Checked-in time unchanged; checked-out time moves to the latest exit. |
| X3 | Stay past the session end, then tap out. | Checked out at the actual exit time. |
| X4 | Leave with the push button. | Door opens; check-out unchanged. |
| X5 | Brand-new card never tapped at the door: tap the inside reader. | Nothing happens. Tap it outside once (even if refused), wait 30 s, try inside again: door opens. |

### Reception check-ins and two sessions in one day

| # | Steps | Expected |
|---|---|---|
| S1 | Member A on a staffed (not self-access) session: **Check in now** on their booking. | Checked in *"by [your name]"*. Card does not open the outside door. |
| S2 | Admin adds Member B to a session that's running now. | Booking is created already checked in. |
| S3 | After S1, Member A taps the inside reader after the session ends (same day). | Staffed booking checked out at the tap time. |
| S4 | Member A checked in to a staffed day session (S1) **without** tapping out, then books an evening self-access session. Enter by card, tap out. | Evening booking checked in and out at the evening times; the day booking still shows *"Not yet"* checked out. |
| S5 | As S4, but in the evening leave, come back, and finally tap out after the evening session has ended. | Evening check-out stays at the earlier exit (known limit); the day booking is still not checked out. |

### Logs

| # | Steps | Expected |
|---|---|---|
| G1 | After the tests above, open Settings → Access log. | Every tap is listed with the right member, booking and outcome. |
| G2 | Open Settings → Cards and click a card used above. | Shows its status and full scan history. |
