# Door access firmware spec — ESP32-S3-ETH, PlatformIO + Arduino core

Companion to `door-access-spec.md` (server-side: schema, PIN lifecycle, `/v1/doors/{door_id}/...`
API). This file is the device side only — everything needed to build the firmware itself,
including OTA. Written to be handed to Claude as the build brief inside a PlatformIO project.

## Hardware note before anything else

**ESP32-S3 has no built-in Ethernet MAC.** The original ESP32 (Xtensa LX6) has an internal EMAC
that drives an external RMII PHY (LAN8720 etc.) via `ETH.begin(...)` — that path does not exist
on S3. Every "ESP32-S3-ETH" board is therefore using an external **SPI-attached** Ethernet
controller, almost always the WIZnet **W5500**. Confirm this against the specific board's
schematic before writing a line of network code — pinout (CS/INT/RST + SPI bus) and which
Ethernet library actually supports the chip are board/core-version specific:

- Recent `arduino-esp32` core (2.0.10+ / 3.x, i.e. what PlatformIO's `espressif32` platform
  pulls in) has grown native SPI-Ethernet support inside `ETH.h` itself
  (`ETH.begin(ETH_PHY_W5500, ...)` style). Verify the exact signature against the installed core
  version — don't assume it matches an example found for a different core version.
- If that's not available/working on the pinned core version, fall back to a dedicated W5500
  library (e.g. `arduino-libraries/Ethernet` or a `W5500lwIP`-style driver) instead of fighting
  the built-in class.
- **First firmware milestone, before anything else in this doc:** bring up the link, get a DHCP
  (or static) address, and hit one plain HTTP endpoint successfully. Don't build credential
  sync/OTA on top of an unproven network stack.

## Pin assignments (starting allocation — no real constraints given yet, treat as placeholder)

No board-specific pinout was available when this was written, so the map below is a reasonable
default rather than anything fixed — swap freely once the actual module/schematic is in hand.
Chosen to avoid known-awkward ESP32-S3 pins: strapping pins `GPIO0`/`GPIO3`/`GPIO45`/`GPIO46`
(boot-mode/voltage selection — using these for anything that could hold them low/high at boot
risks forcing download mode or the wrong flash voltage), the native-USB pins `GPIO19`/`GPIO20`,
UART0 `GPIO43`/`GPIO44` (serial console/flashing), and `GPIO26`–`GPIO37` (claimed internally on
modules with octal PSRAM/flash — common on S3 boards with 8MB+ PSRAM, which this project likely
wants anyway for TLS + JSON parsing + the async web server headroom; confirm your exact module's
memory config either way).

| Function                    | GPIO   | Notes                                              |
|------------------------------|-------|-----------------------------------------------------|
| W5500 SPI — SCK              | 12    | Shared SPI bus                                       |
| W5500 SPI — MOSI             | 11    | Shared SPI bus                                       |
| W5500 SPI — MISO             | 13    | Shared SPI bus                                       |
| W5500 — CS                   | 10    | Chip select, active low                              |
| W5500 — INT                  | 14    | Optional; polling is fine if easier to start with    |
| W5500 — RST                  | 9     | Optional hard-reset line                             |
| Relay driver output          | 4     | Logic level to the relay module, not the lock supply |
| Status LED                   | 2     | Many dev boards already have one on-board — reuse it if convenient rather than wiring a new one |
| Keypad row 1 (output)        | 15    |                                                       |
| Keypad row 2 (output)        | 16    |                                                       |
| Keypad row 3 (output)        | 17    |                                                       |
| Keypad row 4 (output)        | 18    |                                                       |
| Keypad column 1 (input)      | 8     | `INPUT_PULLUP` — no external resistors needed        |
| Keypad column 2 (input)      | 6     | `INPUT_PULLUP`                                       |
| Keypad column 3 (input)      | 7     | `INPUT_PULLUP`                                       |
| Door position sensor (input) | 21    | `INPUT_PULLUP` — reed switch, see § Door position sensing |
| Alarm beeper output          | 5     | Logic level to a piezo/buzzer driver, same "logic not load" pattern as the relay output |

## Project structure (suggested)

```
platformio.ini
src/
  main.cpp             -- setup()/loop(), state machine glue
  net.h/.cpp            -- ETH bring-up, SNTP sync, connectivity watchdog
  api_client.h/.cpp      -- all HTTP calls to the door-access server (Bearer auth, JSON parsing)
  credential_store.h/.cpp -- local cache: full-replace on sync, PIN match, mark-used
  event_queue.h/.cpp     -- durable (flash-backed) queue of attendee_access/keyholder_access/
                             access_denied/unexpected_open, survives a reset mid-access; retried
                             with backoff until acked
  ota.h/.cpp             -- version compare, app + spiffs download/flashing
  keypad.h/.cpp
  relay.h/.cpp
  web_status.h/.cpp      -- local status/config HTTP server, serves from SPIFFS
data/                    -- SPIFFS image source (uploaded as the "spiffs" OTA target)
  status.html
  style.css
```

```ini
; platformio.ini — starting point, not gospel
[env:esp32-s3-eth]
platform = espressif32
board = esp32-s3-devkitc-1        ; replace with the exact board def for your module
framework = arduino
board_build.partitions = min_spiffs.csv   ; needs OTA (ota_0/ota_1) + a spiffs slot — see OTA section
monitor_speed = 115200
lib_deps =
  bblanchon/ArduinoJson @ ^7
  ESP32Async/ESPAsyncWebServer         ; local status page — non-blocking, won't stall keypad handling
  ESP32Async/AsyncTCP
```

`board_build.partitions`: the default single-app partition table has **no OTA slots at all** —
`Update.h`-based OTA silently can't work without one. `min_spiffs.csv` (ships with the core) gives
`ota_0`/`ota_1`/`otadata` plus a small SPIFFS partition — start there, and only hand-roll a custom
`partitions.csv` if the template assets don't fit in it.

## Version scheme

Format `YYYYMMDDXX` (e.g. `2026091301` = 2nd release on 2026-09-13) — a plain ascending integer,
fits comfortably in a `uint32_t` (max ~4.29 billion; this format doesn't reach that until roughly
the year 4294). Store the running version as a compile-time constant:

```cpp
// version.h — bump this every release, nothing else needed for the compare logic
#define FIRMWARE_VERSION 2026091301UL
```

Comparison is just `serverVersion > FIRMWARE_VERSION` — no semver parsing, no string compare.

## How the server tells the device an update exists

Per the proposal: **every** existing API response (`/credentials` poll, `/heartbeat` reply,
`/events` ack) carries the current required version, piggybacked on network round-trips the
device is already making — no extra polling just to check for updates:

```json
{
  "server_time": "2026-09-13T14:32:00Z",
  "min_firmware_version": 2026091301,
  "...": "...rest of the existing response body, unchanged..."
}
```

The device checks this field on **every** response it parses, regardless of which endpoint it
came from. Firmware URLs are fixed, predictable patterns keyed on the version number — no
manifest/lookup endpoint needed at all, the device just builds the two URLs itself once it knows
which version it's behind:

```
https://www.cardiganclimbing.org/firmware/app/{version}.bin
https://www.cardiganclimbing.org/firmware/spiffs/{version}.bin
```

```cpp
String appUrl    = "https://www.cardiganclimbing.org/firmware/app/"    + String(minFirmwareVersion) + ".bin";
String spiffsUrl = "https://www.cardiganclimbing.org/firmware/spiffs/" + String(minFirmwareVersion) + ".bin";
```

Size and integrity checking still matter (see OTA sequence below) without a manifest to carry
them — read them off the download response itself rather than a separate lookup:
`Content-Length` gives the size for `Update.begin()`, and the server should add one custom
header (e.g. `X-Firmware-MD5`) alongside the binary for `Update.setMD5()`. This is a small
server-side addition (setting one extra header on an otherwise static-file-shaped response) —
see `door-access-spec.md`, which already needs updating for `min_firmware_version` on every
response and should pick this up alongside it.

## OTA sequence

State machine addition to the existing idle/poll loop:

```
IDLE ──(min_firmware_version > running, and idle)──▶ OTA_DOWNLOAD_SPIFFS
OTA_DOWNLOAD_SPIFFS ──▶ OTA_DOWNLOAD_APP ──▶ REBOOT
                   └─(any step fails)──▶ IDLE  (log, back off, retry — see Retry policy below)
```

- **SPIFFS first, then app, then reboot.** If the process is interrupted between the two, "new
  templates + old app" is the safer half-done state than the reverse — the running app doesn't
  depend on the new template content matching exactly.
- Flashing — both binaries are fetched from their fixed, version-keyed URLs (above), with size
  and hash read off the response itself rather than a manifest:
  ```cpp
  // SPIFFS/data partition — U_SPIFFS is the correct constant even if the partition is actually
  // mounted with LittleFS; it names the partition role, not the filesystem driver.
  HTTPClient http;
  http.begin(spiffsUrl);
  int size = http.getSize();                          // from Content-Length
  String md5 = http.header("X-Firmware-MD5");
  Update.begin(size, U_SPIFFS);
  Update.setMD5(md5.c_str());
  // stream http.getStream() into Update.write(...) in chunks; Update.end(true) verifies + commits

  // App partition — targets whichever ota_N slot isn't currently running, standard A/B swap.
  // Same shape: GET appUrl, Update.begin(size, U_FLASH), Update.setMD5(...), stream, Update.end(true).
  // Only ESP.restart() once BOTH have committed successfully.
  ```
  Use `Update.setMD5()` before `begin()` for both — a size or hash mismatch aborts the flash
  instead of committing a corrupt partition. If the server doesn't send `X-Firmware-MD5` (or
  `Content-Length` is missing/chunked), that's a hard-fail on that attempt, not a silent skip of
  the integrity check — retry later rather than flash unverified.
- **When OTA is allowed to run:** never mid-keypad-entry. Gate the transition into
  `OTA_DOWNLOAD_SPIFFS` on "no PIN entry in progress" and re-check idleness before actually
  starting each download (someone could start typing mid-transfer). If the door is mid-interaction
  when an update becomes due, just defer to the next idle window/poll cycle — don't interrupt a
  real access attempt to update firmware.
- **Boot self-validation / rollback:** `arduino-esp32`'s standard OTA-capable partition tables
  (including `min_spiffs.csv`) provide the dual app-slot (`ota_0`/`ota_1`) structure that ESP-IDF's
  rollback mechanism relies on, but whether automatic rollback-on-repeated-crash is actually
  *armed* depends on a bootloader Kconfig option whose default in the prebuilt Arduino core isn't
  something to assume blind — **verify this on real hardware early** (flash a deliberately-broken
  build and confirm it actually rolls back) rather than trusting it works. Pragmatic belt-and-
  braces regardless of that: after a fresh OTA boot, require one successful `/credentials` poll
  within e.g. 2 minutes before calling `esp_ota_mark_app_valid_cancel_rollback()`; if that never
  happens, a watchdog-forced reboot at least gives the rollback mechanism (if armed) a chance to
  act rather than the device silently limping along on broken firmware.
- Persist "OTA in progress" / "pending validation" flags in `Preferences` (NVS), not just RAM —
  a mid-flash brownout shouldn't leave the device in an undefined state on next boot.

## Power-loss resilience during access

A relay/solenoid firing draws a current spike that, on a cheaply-decoupled board, can sag the
logic rail enough to trip the ESP32's brownout detector — an immediate, unclean reset, right at
the exact moment the door is unlocking. Two separate problems follow from that, and both need a
firmware answer:

1. Everything held only in RAM (the in-progress access decision, any queued-but-unsent event) is
   gone the instant that reset happens.
2. The server may never learn this access happened at all, if the reset hits before the
   `attendee_access` POST went out.

**Hardware mitigation first, firmware second — don't try to solve this in software alone.** The
root cause is almost always the relay/lock coil sharing an inadequately-decoupled supply with the
MCU logic rail. Drive the lock from its own supply (or at minimum a beefy local reservoir
capacitor + flyback diode right at the relay/solenoid, on a rail separate from the 3.3V logic
feed) as a hardware fix alongside everything below — firmware can make a brownout non-catastrophic,
it can't make current spikes disappear. Don't "fix" this by just disabling the brownout detector
in software (`RTC_CNTL_BROWN_OUT_REG` register poke) — that trades a clean, recoverable reset for
the chip potentially continuing to run (and writing to flash) at an out-of-spec voltage, which
is worse.

**Ordering that closes the race window as tightly as possible**, once a PIN has matched locally:

```
1. Write a durable "access granted, pending" record for this credential_id — flash, not RAM —
   BEFORE touching the relay at all. Stamp authorized_at now.
2. Pulse the relay.
3. Update the durable record to "relay pulsed" (still pending server ack).
4. Mark the credential used in the in-memory cache (blocks reuse before the next sync lands).
5. Enqueue the event for the retrying API-call path at its current stage (see below) — this step
   can fail and retry indefinitely; the door has already opened, nothing user-facing depends on it
   succeeding promptly.
6. Hand off to the door-position state machine (§ Door position sensing) to observe the physical
   open/close and progress the same record's stage — and hence what gets (re-)sent for the same
   event_id — through door_open and finally door_closed. See that section for the full detail;
   this list only covers what happens up to the relay pulse.
```

If a reset lands anywhere in steps 1–3, step 1's durable record survives it. **On boot, recover
by resuming the server sync only — never by re-pulsing the relay.** An unattended reboot
re-firing a lock with no one necessarily standing there is a security problem, not a convenience;
worst case if the reset landed mid-pulse, the physical pulse may have been cut short (a hardware
mitigation — capacitor hold-up on the relay driver — is the real fix for that, not firmware
re-firing it blind). The durable record just needs to guarantee the server eventually finds out
access was granted and the PIN stays consumed (never re-matchable) across the reset either way.

**"Resume the server sync" is not the whole recovery — a record already at `door_open` when the
reset lands must resume door-position tracking too, not just retry the network side.** The whole
point of persisting `door_open_at` to flash (§ Door position sensing) rather than keeping it only
in RAM is so this is recoverable: at boot, for every loaded record whose `stage` is `door_open`,

1. Read the door sensor right now.
2. **Still open:** this is the same access continuing, not a new one — resume `OPEN[attendee]` or
   `OPEN[keyholder]` (per the record's `type`) with elapsed time computed as
   `now - door_open_at`, using the persisted, wall-clock `door_open_at` — exactly why that field is
   `time_t` and not `millis()` (§ Power-loss resilience, `PendingEvent` struct). If that elapsed
   time already exceeds the applicable threshold, go straight into `ALARM` (beeper on immediately)
   rather than waiting to freshly cross it — the propped-door condition didn't go away just because
   the ESP did.
3. **Now closed:** the door closed at some point during the outage. There's no way to know exactly
   when, so stamp `door_closed_at = now` (best available) and advance the record straight to
   `door_closed`, sending the completed event — don't leave it stranded at `door_open` waiting for
   a sensor transition that already happened.

This is what makes a brownout mid-access a non-event from the server's perspective: the same
`event_id` picks up exactly where it left off, whether that's "still counting" or "actually already
finished" — never a dropped/restarted flow. Contrast this with the separate boot sanity-check
below, which covers the opposite case: the sensor reads open at boot with **no** matching pending
record to explain it.

**Storage: use LittleFS for this, not legacy SPIFFS**, specifically because of this requirement —
LittleFS is designed for power-loss resilience (copy-on-write-style metadata, doesn't corrupt the
filesystem on an unexpected reset); SPIFFS predates that guarantee and is the reason the ESP32
Arduino ecosystem has been moving away from it. This doesn't change anything already said about
the OTA partition — it's still labelled/updated as the `spiffs` partition/`U_SPIFFS` target
regardless of which filesystem is mounted on it; just mount it with `LittleFS.h` (built into
recent `arduino-esp32` cores, no extra `lib_deps` entry needed) rather than `SPIFFS.h`.

Implementation shape — a small fixed-capacity ring buffer of fixed-size records in a single
LittleFS file (`/pending_events.dat`), not a growing append-only log:

```cpp
struct PendingEvent {
  char     event_id[37];     // client-generated UUID, matches the parent spec's idempotency key
  uint8_t  type;             // attendee_access | keyholder_access | access_denied | unexpected_open
  uint32_t credential_id;    // valid for attendee_access / access_denied, else 0
  uint32_t keyholder_user_id;// valid for keyholder_access, else 0 — see § Keyholder disarm PIN
  uint8_t  stage;            // authorized | door_open | door_closed — see § Door position sensing.
                              // access_denied has no stage progression, always written straight in
                              // as the final/only stage. unexpected_open skips `authorized`
                              // entirely and starts at door_open — nothing authorized it.
  uint8_t  acked;            // has the server confirmed the CURRENT stage's data yet
  time_t   authorized_at;    // 0 for unexpected_open
  time_t   door_open_at;     // 0 until observed
  time_t   door_closed_at;   // 0 until observed
};
// Fixed slot count (e.g. 64) at fixed offsets — no filesystem-level append/growth needed,
// read/write by index, load the whole table into RAM at boot to rebuild the retry queue.
```

64 slots is comfortably more than a single-keypad door will ever queue between one 20–30s poll
cycle and the next, let alone during a real outage — see Open items for what happens if an
extended outage actually fills it.

**The one rule that matters most, regardless of anything else in this section:** a slot is only
ever cleared after the server has actually acknowledged that specific `event_id` — never on
merely having been read into memory for sending. A queue that removes-on-read rather than
removes-on-ack looks identical in the happy path and silently loses events under exactly the
failure conditions this design exists to survive. With `stage` now progressing on the same
`event_id` (below), that rule sharpens slightly: a slot is only cleared once the server has acked
the `door_closed` stage specifically — an ack of an earlier stage just clears `acked` back to
false so the next stage gets sent, it doesn't retire the slot.

## Door position sensing (tailgating / propped-door alarm)

A reed switch on the door/frame, plus a beeper for the alarm case — this turns "PIN was valid and
the relay fired" into "someone actually walked through, and how long that took," and gives a way
to notice a door left open rather than just a PIN typed.

**Wiring convention:** `INPUT_PULLUP` on the sensor GPIO, reed switch to ground. **Confirmed NC**
(normally closed) — the switch contact is closed (continuity, magnet present) when the door is
shut, so with the magnet away (door open) the circuit breaks and the pull-up takes the GPIO high:

```cpp
const bool DOOR_SENSOR_CLOSED_WHEN_LOW = true;   // NC switch: door closed → contact closed →
                                                   // GPIO pulled to ground → reads LOW
```

This is a fixed constant now, not a "confirm on real hardware" placeholder like the other values
on this page — it's set from the actual switch spec, not guessed. Debounce the same way as the
keypad (§ Keypad driver) — a door/frame reed switch chatters on contact just like a key does.

**Extends the access flow from § Power-loss resilience into a longer-lived local state machine**,
one instance at a time (only one access is ever in progress on a single-keypad door):

```
IDLE ──(attendee PIN matched)──▶ AWAITING_OPEN[attendee]
                 relay pulsed, durable record written (type=attendee_access), stage=authorized (send)
IDLE ──(keyholder PIN matched — § Keyholder disarm PIN)──▶ AWAITING_OPEN[keyholder]
                 NO relay pulse, durable record written (type=keyholder_access), stage=authorized
                 (send), log auth/info keyholder_pin_used immediately

AWAITING_OPEN[either] ──(sensor: closed→open, within 10s)──▶ OPEN[attendee] / OPEN[keyholder]
                 stamp door_open_at, stage=door_open (send/resend) — carries forward which path
                 authorized this, since that decides which alarm threshold applies next
AWAITING_OPEN[attendee] ──(open-wait timeout, e.g. 10s, no open seen)──▶ IDLE
                 log hardware/warn door_never_opened — possible strike/sensor fault, or the PIN
                 owner simply walked away; not alarmed (nothing is propped), but worth knowing
                 about if it keeps happening
AWAITING_OPEN[keyholder] ──(same timeout, no open seen)──▶ IDLE
                 no warning log, no access_event at all — see § Keyholder disarm PIN for why a
                 keyholder PIN typed without immediately walking through is ordinary, not a fault

OPEN[attendee] ──(sensor: open→closed, within ATTENDEE_ALARM_THRESHOLD_S)──▶ IDLE
                 stamp door_closed_at, stage=door_closed (send/resend) — completes the access_event
OPEN[attendee] ──(still open past ATTENDEE_ALARM_THRESHOLD_S, e.g. 20s)──▶ ALARM (start beeper)

OPEN[keyholder] ──(sensor: open→closed, within KEYHOLDER_ALARM_THRESHOLD_S)──▶ IDLE
                 stamp door_closed_at, stage=door_closed (send/resend) — completes the access_event
OPEN[keyholder] ──(still open past KEYHOLDER_ALARM_THRESHOLD_S, 10 minutes)──▶ ALARM (start beeper)
                 a keyholder legitimately propping the door open — moving gear in and out, holding
                 it for a group — is expected behaviour, not a fault; 10 minutes is deliberately
                 far longer than ATTENDEE_ALARM_THRESHOLD_S so that doesn't trip the same alarm a
                 forgotten/propped attendee-opened door would

ALARM ──(sensor: open→closed)──▶ IDLE
                 stamp door_closed_at, stage=door_closed (send/resend), stop beeper immediately
                 — late completion is still completion, just also logged (see below); the beeper
                 behaviour once ALARM is entered is identical regardless of which threshold got it
                 there

IDLE ──(sensor: closed→open, with nothing authorized)──▶ UNEXPECTED_OPEN
                 start beeper immediately (no grace period — unlike ALARM above, there is no
                 legitimate access in flight to give the benefit of the doubt to), log
                 hardware/error door_unexpected_open immediately, AND write a durable record
                 (type=unexpected_open, no authorized_at, starts straight at stage=door_open,
                 send) — this is exactly the "non-PIN event" the access_event table exists to
                 track, not just a diagnostic-log line
UNEXPECTED_OPEN ──(sensor: open→closed)──▶ IDLE, stop beeper immediately
                 stamp door_closed_at, stage=door_closed (send/resend) for the same event_id
```

- **Two separate alarm thresholds, not one** — the whole point of tracking which path (attendee
  vs. keyholder) put the door into `OPEN` is picking the right one:
  ```cpp
  const uint32_t ATTENDEE_ALARM_THRESHOLD_S  = 20;    // placeholder — needs real-world tuning
  const uint32_t KEYHOLDER_ALARM_THRESHOLD_S = 600;   // 10 minutes — a firm requirement, not a
                                                        // placeholder like the others on this page
  ```
  A member walking through a self-access door should take seconds, so a short threshold catches a
  genuinely propped/stuck door quickly. A keyholder may deliberately prop the door open for
  extended work — moving gear, holding it for a group — so their threshold has to comfortably
  cover that without alarming on legitimate use. **10 minutes is an explicit product requirement**
  (not a "tune this on real hardware" placeholder like the 10s/20s figures elsewhere on this page)
  — track which threshold is live as a plain variable set when entering `OPEN[attendee]` vs.
  `OPEN[keyholder]`; it's a RAM-only concern, not something that needs to survive a reset (see
  Open items for the one edge case a reset does raise here).
- **Timeouts otherwise still placeholders** (10s to open, `ATTENDEE_ALARM_THRESHOLD_S`) — real
  values need tuning against the actual door/lock hardware once it exists; see Open items.
- **Beeper behaviour (`ALARM`):** start on entering `ALARM`, stop the instant the door closes —
  don't run it on a fixed duration timer that could out-last (or under-run) the actual open state.
  Also log a `hardware`/`error` diagnostic entry the moment `ALARM` is entered ("door open N s,
  exceeds threshold") — this is exactly the "nobody has serial access" scenario the diagnostic log
  exists for; include which threshold was exceeded in the log's context fields (`context_a` =
  seconds open, `context_b` = the threshold that applied) so it's clear from the log alone whether
  this was a fast attendee-door timeout or a genuinely very-long keyholder one. Once `ALARM` is
  entered, the beeper itself behaves identically regardless of which threshold got it there — a
  propped door is a physical problem either way, only the grace period before calling it one
  differs. If the door is still open well past `ALARM` (e.g. another few minutes on the attendee
  side, or well beyond 10 minutes on the keyholder side), that's a physical problem a beeper alone
  won't fix — don't add escalating beeper patterns for this, one clear "something needs attention"
  tone is enough; a person still needs to go and look.
- **Unexpected door open — no access in progress, in scope for v1:** if the sensor reports
  closed→open while the state machine is `IDLE` (nothing was authorized first, by PIN or
  keyholder code), that's treated as urgent from the first instant, not after a grace threshold —
  `ALARM`'s timeout exists to give a legitimate, already-authorized visitor a reasonable window to
  walk through and shut the door; `UNEXPECTED_OPEN` has no such visitor to give the benefit of the
  doubt to, so the beeper starts immediately on detection. This is squarely the
  "tailgating/forced entry" territory the hardware is being added for, even though it wasn't the
  original trigger (a second person following someone through, or a door forced/propped without
  ever using the keypad). Same beeper output as `ALARM` — no separate tone/pattern required for
  v1, one alarm sound covers both cases; if it later turns out staff need to tell them apart at a
  glance, the diagnostic log and the `access_event.type` already distinguish the two causes even
  though the sound doesn't.
- **Sanity-check the sensor itself at boot — but only once the resumption check above has run.**
  If the sensor reads "open" at startup and there's **no** loaded `door_open`-stage record to
  explain it (the case actually covered here — a genuinely legitimate open, mid-keyholder-prop or
  otherwise, always has one, per § Power-loss resilience's boot recovery), log a `hardware`/`warn`
  entry rather than silently trusting a possibly-stuck-open or miswired sensor. Don't let a bad
  sensor generate a false alarm loop, but don't stay silent about it either.
- **Every stage transition re-sends the same `event_id`** with the new stage and whichever
  timestamps are now known — see the updated `PendingEvent` struct above and the parent spec's
  events endpoint, which now applies stage progression as an upsert (never regresses on a
  reordered/duplicate retry) rather than treating every POST as a brand-new event.
- `access_denied` events are unaffected by any of this — there's no relay pulse and nothing to
  open, so they're written and sent exactly as before, no stage progression.

## Keyholder disarm PIN

Some staff can already open the door with a physical key, bypassing the keypad and relay
entirely. This PIN's only job is telling the firmware "expect this" a few seconds before that
happens, so the door opening isn't flagged as `UNEXPECTED_OPEN`. **It never triggers the relay.**

**Matching order on keypad submit:** check the attendee credential cache first (the common case,
checked on every submission), then the much smaller keyholder cache as a fallback if nothing
attendee-side matched. Collision between the two PIN pools is prevented at the source
(`door-access-spec.md` § PIN lifecycle — attendee PIN generation excludes active keyholder PINs,
and vice versa), so this ordering is purely a minor efficiency choice, not something correctness
depends on.

**On a keyholder PIN match:**
1. Log `auth`/`info` `keyholder_pin_used` immediately, `context_a = user_id` — audit-worthy on its
   own, independent of whether the door then opens.
2. Write a durable record and transition into `AWAITING_OPEN[keyholder]` (§ Door position
   sensing) — **no relay pulse.** The keyholder still needs their physical key; this only
   suppresses the unexpected-open alarm for the next ~10s.
3. If the door opens within that window, it folds into the same `OPEN`/`ALARM` handling as an
   attendee access — a keyholder who props the door open past the threshold sets off the same
   beeper as anyone else. Produces an `access_event` of `type=keyholder_access` carrying
   `user_id` instead of `credential_id`.
4. If the 10s window elapses with no open, return to `IDLE` quietly — **no warning-level log, no
   `access_event` at all.**

**Why a timeout-with-no-open is a warn-level log on the attendee path but silent (beyond step 1)
on the keyholder path:** an attendee's PIN is single-use and just got consumed for nothing —
that's worth flagging (`door_never_opened`) because it might mean a broken strike. A keyholder's
PIN is a standing credential, not consumed by use, and typing it without immediately walking
through (pausing, getting distracted, heading to a different door) is completely ordinary — the
one thing genuinely worth recording, the fact the code was used at all, already happened at step 1.

**A second keyholder PIN entry while already in `AWAITING_OPEN[keyholder]`/`OPEN`/`ALARM`** for an
existing keyholder-authorized attempt is just another step-1 log entry — it doesn't reset or
extend the current window. Keep this simple rather than defining stacking semantics nobody's
asked for.

**Sync:** the keyholder cache rides the same poll as attendee credentials
(`door-access-spec.md`'s `GET /v1/doors/{door_id}/credentials`, now with a sibling `keyholders`
array) — same authoritative-full-replace rule, same "operate on the last-good cache if a poll
fails" resilience story as the credential cache, no separate poll cadence or endpoint needed.

## Retry policy for API calls

Don't assume any single HTTP round-trip succeeds first time — a flaky mobile/broadband uplink at
the venue is the normal case to design for, not the exception. One shared retry policy, applied
consistently rather than each call site inventing its own:

- **Never retry inline with a blocking wait.** `HTTPClient` calls are blocking per-request, but
  the *retry loop* itself must not be — one attempt per call, track backoff state, return control
  to `loop()`, try again on a future tick. A `delay()`-based retry loop here would stall keypad
  polling for the entire outage, which is the one thing that must never happen. Concretely, that
  means a deadline timestamp checked non-blockingly, not a `for`/`while` loop with `delay()`
  between attempts:
  ```cpp
  // Called every loop() tick — does nothing until backoffUntil has passed, then makes exactly
  // one attempt and reschedules on failure. No delay() anywhere in this path.
  if (millis() < backoffUntil) return;

  bool ok = attemptSend();
  if (ok) {
    failCount = 0;
  } else {
    uint8_t exponent = min(failCount, MAX_BACKOFF_EXPONENT);
    failCount++;
    backoffUntil = millis() + min(BASE_BACKOFF_MS << exponent, MAX_BACKOFF_MS);
  }
  ```
- Set explicit, short timeouts on every request (`http.setConnectTimeout()` /
  `http.setTimeout()` — a few seconds, not the library default) so one stalled attempt can't
  block the next scheduled action for longer than that.
- Exponential backoff with a cap, per call type — not the same policy for everything:
  - **`attendee_access` / `keyholder_access` / `access_denied` / `unexpected_open` events**: must eventually get through — retry
    indefinitely (bounded only by the 64-slot queue capacity, not by a retry-count cutoff).
    Backoff starting at ~5s, doubling, capped around ~2 minutes between attempts once the outage
    is clearly not momentary.
  - **`/credentials` poll**: the existing 20–30s poll cadence *is* the retry — no separate backoff
    needed, just keep operating from the last-good cache (see staleness question in Open items)
    and try again next cycle.
  - **Heartbeat**: least critical of all of these — retry on the next scheduled heartbeat, no
    special handling needed.
  - **OTA binary fetch**: retry, but debounced separately from the fast credential-poll
    cadence — e.g. don't re-attempt a failed multi-hundred-KB download on every 20–30s poll tick;
    back off to retrying every few minutes instead. A failed download must leave `Update` in a
    clean, restartable state (abort via `Update.abort()` / just let the half-finished attempt be
    discarded) rather than half-committed.
- Every queued event carries the same client-generated `event_id` on every retry attempt — the
  server's ack is keyed on that (per the parent spec), so a retry that actually succeeded but
  whose response got lost in transit doesn't double-apply on the next attempt.

## Diagnostic log (separate from the access-event queue)

Once this thing is installed, there's no serial console to plug into — this log is the *only*
window onto what a device did while unattended. That changes the design goal from the earlier
"why did this go offline for two hours on Tuesday, eventually" framing: this needs to be a
structured, promptly-uploaded stream of *exceptional* events, not a plain-text file dumped once a
day. Still a different, lower-stakes concern from the pending-events queue above (losing the
oldest entry under sustained space pressure is acceptable here, unlike an access event), so it
stays a separate mechanism — just a more capable one than originally sketched.

**What generates a log entry — and just as importantly, what doesn't.** The point is signal, not
volume: routine successful operation (a normal poll, a normal heartbeat, a normal PIN match) never
writes an entry. Only things outside standard operation do:

| Category   | Level | Reason slug            | Trigger |
|------------|-------|-------------------------|---------|
| `boot`     | info  | `boot`                  | Every boot, reset reason from `esp_reset_reason()` (power-on / external / SW restart) |
| `boot`     | error | `boot_brownout`         | Boot where the reset reason is brownout or a task-watchdog panic — the exact case § Power-loss resilience exists for; this is how you'd ever find out one happened |
| `ota`      | info  | `ota_progress`          | Update detected, download started, flash committed pending validation |
| `ota`      | error | `ota_failed`            | Download or flash failed at either stage (which one, and why — bad size/MD5 mismatch/HTTP error) |
| `ota`      | error | `ota_validation_failed` | Post-OTA validation window expired without a successful poll → forced reboot (§ OTA sequence) |
| `auth`     | warn  | `pin_rejected`          | PIN rejected — reason only (`expired`/`not_found`/`already_used`), **never the PIN digits themselves**, in this or any other log |
| `auth`     | warn  | `lockout_entered`       | Lockout entered (5 consecutive failures) |
| `auth`     | info  | `lockout_cleared`       | Lockout cleared |
| `auth`     | info  | `keyholder_pin_used`    | Keyholder PIN matched (§ Keyholder disarm PIN) — logged regardless of whether the door is then opened |
| `sync`     | warn  | `sync_degraded`         | Credential poll has now failed N consecutive times (e.g. 5, ~2 min of outage) — entering degraded/cache-only mode |
| `sync`     | info  | `sync_recovered`        | Recovered — first successful poll after a degraded run, with how long it lasted |
| `hardware` | warn  | `queue_high_water`      | Pending-event queue crossed a high-water mark (e.g. 80% of 64 slots) — early warning before anything is actually lost |
| `hardware` | error | `queue_data_loss`       | Pending-event slot overwritten before the server acked it — actual data loss, not just a warning sign |
| `hardware` | warn  | `door_never_opened`     | Relay pulsed but the door sensor never saw an open within the wait timeout (§ Door position sensing) |
| `hardware` | error | `door_propped`          | Door left open past its alarm threshold after a legitimate access — beeper sounding. `context_a`/`context_b` carry seconds-open and the threshold that applied, since attendee (20s) and keyholder (10 min) thresholds differ |
| `hardware` | error | `door_unexpected_open`  | Door opened with no active access in progress — beeper sounding, in scope for v1 |
| `hardware` | warn  | `door_sensor_stuck`     | Door sensor reads "open" at boot with no loaded `door_open`-stage record to explain it (not "relay never pulsed" — a legitimate keyholder open never pulses the relay either) |
| `config`   | info  | `config_changed`        | Reserved for the future settings-page phase (§ Local status/config web server) — no config mutations exist yet to log |

If a future case doesn't fit this table, default to `warn` and add it here rather than silently
skipping it — the cost of an extra log line is far lower than the cost of a blind spot on a
device nobody can plug a cable into. **The reason slug is machine-readable and stable** — it's
what the server matches on for anything automated (see the server-side alerting note in
`door-access-spec.md`), so treat renaming one as a breaking change, not a copy edit; the free-text
`message` field is for a human reading the raw log, never for a server-side rule to parse.

**Local storage — same ring-buffer shape as the pending-events queue, for the same reasons:**
fixed-size records, fixed slot count, no filesystem-level append/growth, in a single LittleFS
file (`/log_events.dat`), separate from `/pending_events.dat`.

```cpp
struct LogEntry {
  char     log_id[37];    // client-generated UUID, same idempotency role as event_id
  uint8_t  level;         // info | warn | error
  uint8_t  category;      // boot | ota | auth | sync | hardware | config
  char     reason[24];    // stable machine-readable slug from the table above, e.g. "door_propped"
                           // — this, not `message`, is what server-side alerting rules match on
  time_t   timestamp;     // wall-clock (SNTP) — same reasoning as PendingEvent, not millis()
  uint32_t context_a;     // meaning depends on category, e.g. ota: from_version
  uint32_t context_b;     // meaning depends on category, e.g. ota: to_version
  char     message[64];   // short human-readable string, truncated to fit — fixed-size record,
                           // not a variable-length one, same tradeoff as PendingEvent
};
// 128 slots — double the pending-events queue, since these accumulate faster (a noisy sync
// outage logs two entries total, not one per failed poll) but are individually lower-stakes.
```

Unlike `/pending_events.dat`, it's acceptable for a full log ring to overwrite its oldest
unacknowledged slot rather than refuse new entries — but do it noisily: writing over a slot that
was never acked emits one more synthetic `hardware`/`warn` entry (`log_ring_overflow`, "N log
entries dropped, ring full") into the *new* slot being written, so the server at least learns a
gap exists even if it never sees what filled it.

**Upload:** batched to the server's new log endpoint (see `door-access-spec.md`), using the same
non-blocking backoff mechanics as § Retry policy for API calls, idempotent per `log_id` exactly
like the access-event queue — a slot is only cleared once the server acks that specific `log_id`,
never merely on having been read for sending.

- **Cadence:** flush whatever's queued every ~5 minutes normally — frequent enough that "the
  device did something weird" is visible in near-real-time rather than the next day, without
  generating meaningful traffic at this volume.
- **Escalate on `error`:** don't wait for the next scheduled flush if an `error`-level entry is
  sitting in the queue — trigger a flush within the next ~30s instead. Everything else in this
  design assumes remote visibility matters more for errors than for routine info entries; the
  upload cadence should reflect that too.
- Batch every queued entry into one POST (same shape as `/events`), not one request per entry.
- Build each entry's JSON at send-time from the fixed struct fields — the on-device record itself
  stays fixed-size/binary; there's no benefit to storing JSON text at rest here.

## Local status/config web server

Two different roles — don't conflate them:

1. **Device as HTTP client**, pulling credentials/firmware from the cloud server (above).
2. **Device as HTTP server**, serving a status page to a browser on the local network — this
   section.

Use `ESPAsyncWebServer` (async/non-blocking) rather than the synchronous `WebServer.h` — serving
a page to someone's laptop on the LAN must never stall keypad polling or relay timing. Ethernet
vs Wi-Fi is irrelevant to the choice here; both async and sync web server classes on `arduino-esp32`
sit on top of the same LWIP stack regardless of which network interface is up.

**Why templates live in the SPIFFS image (and get OTA'd separately from the app binary):**
the local UI's HTML/CSS is presentation, not logic — shipping it as its own OTA target means the
status page (and later, settings pages) can be redesigned/extended by pushing a new SPIFFS image
alone, no app-binary rebuild or re-flash required for a copy/layout change.

- **v1 scope: read-only system status page only**, per the brief — no settings/config UI yet.
  - Route: `GET /` → serves `data/status.html` from SPIFFS.
  - Simple `%TOKEN%` placeholder substitution (`String::replace()` on the loaded template) rather
    than pulling in a real templating engine — this is a handful of values, not a general-purpose
    view layer:
    ```
    %FW_VERSION%       -- e.g. "2026091301" (optionally decode to "2026-09-13 rev 01" for display)
    %IP_ADDRESS%, %MAC_ADDRESS%, %LINK_STATE%
    %UPTIME%
    %LAST_SYNC_AT%, %LAST_SYNC_RESULT%     -- ok / failed, with the last error if failed
    %CACHED_CREDENTIAL_COUNT%
    %RELAY_STATE%
    %FREE_HEAP%, %FREE_FLASH%
    %PENDING_EVENT_COUNT%    -- how many queued access events haven't POSTed yet
    %PENDING_LOG_COUNT%      -- how many queued diagnostic log entries haven't POSTed yet
    ```
  - No auth for v1 (read-only, no secrets rendered) — reconsider once settings pages exist (below).
- **Future phase, not required now:** settings pages (door API key rotation, static IP override,
  poll interval, Ethernet vs. fallback config) — these *mutate* device credentials/behaviour, so
  they need a local admin password (stored in `Preferences`, set on first boot or via a physical
  button/serial provisioning step — don't ship a hardcoded default) before that route group is
  added. Flagging now so the status-page routing/template loader is structured to grow into this
  rather than needing a rewrite.

## Keypad driver (12-key, 4×3 matrix)

Standard phone-style layout (`1 2 3 / 4 5 6 / 7 8 9 / * 0 #`) wired as 4 rows × 3 columns — 7 GPIOs
total, per the pin table above.

**Hand-roll the scanner rather than pulling in a generic keypad library.** This is a security-
relevant input path with its own timing/debounce/lockout requirements already speced (below) —
a small, fully-understood scanner integrates with that state machine directly, rather than
wrapping a third-party library's own event model around it. (The Arduino `Keypad` library by Mark
Stanley & Alexander Brevig is a perfectly reasonable alternative if hand-rolling this isn't
wanted — just confirm its exact PlatformIO registry name/version before adding it as a
`lib_deps` entry, rather than assuming a specific string here.)

**Wiring convention:** columns as `INPUT_PULLUP` (idle high; a keypad is just momentary switches
with no onboard resistors, so the ESP32's internal pull-ups are all that's needed — no external
resistor network). Rows as `OUTPUT`, idle high, driven low one at a time during a scan.

**Scan algorithm**, run from a non-blocking timer tick (~10–20ms interval — never `delay()` in
this path, consistent with every other loop-blocking rule in this doc):

```
for each row (0..3):
  drive that row LOW, all other rows HIGH
  (brief settle — a few µs is enough, GPIO switching is fast)
  for each column (0..2):
    if column reads LOW → this (row, col) is the pressed key
  restore row HIGH
```

**Debounce + entry state machine**, layered on top of the raw scan:

- A key reading must be stable for ~2 consecutive scans (~20–40ms) before it's accepted as a
  real press — filters contact bounce.
- Require a full release (no key pressed) before the same or another key can register again —
  stops a held key from auto-repeating into the PIN buffer.
- Digits (`0`–`9`) accumulate into the current entry buffer. At 6 digits (the PIN length locked
  in by the parent spec), auto-submit — feed the buffer straight into the existing "Keypad match"
  logic (see below) without waiting for further input.
- `*` clears the current (possibly partial) entry — a mis-typed attempt starts over rather than
  submitting garbage.
- `#` is unused for now given a fixed PIN length makes an explicit "submit" redundant — reserved
  for later (e.g. a future settings/admin PIN entry mode) rather than bound to anything yet.
- **Idle entry timeout:** if a partial (non-empty, non-submitted) entry sits untouched for ~10s,
  clear it automatically — a stale half-typed PIN shouldn't linger for the next visitor to find.
- **During the lockout cooldown** (5 consecutive failures → 30–60s, per the parent spec): keep
  scanning and accepting keystrokes as normal — reject immediately without even checking the
  credential cache, and don't reset or extend the cooldown timer just because someone kept
  typing. A keypad that stops responding entirely during lockout reads as broken hardware, not a
  deliberate cooldown.

## Everything from the parent spec, restated here as the firmware's own responsibility

(See `door-access-spec.md` for the server-side reasoning behind each of these — restated here so
this file is a complete brief on its own.)

- Boot: Ethernet link up → SNTP sync (all comparisons in UTC, no local-timezone handling
  anywhere) → full credential pull before accepting keypad input.
- Poll `GET /credentials` every 20–30s. **Treat every response as an authoritative full replace**
  of the local cache — overwrite each `credential_id`'s pin/valid_from/valid_until/status
  wholesale, and drop any cached entry missing from the latest response (no longer relevant to
  this door). Same rule for the sibling `keyholders` array (§ Keyholder disarm PIN). This is what
  makes server-side PIN regeneration work with zero special-case firmware logic — a regenerated
  PIN just looks like a normal updated cache entry next poll.
- On failure, keep operating from the last-good cache rather than locking out everyone.
- Keypad match, checked in order: attendee credential cache first, then keyholder cache.
  - Attendee: PIN + `now` within `[valid_from, valid_until]` + not already used locally → pulse
    relay, **mark used locally immediately** (closes the window for double-use before the next
    sync lands), queue an `attendee_access` event at its `authorized` stage. Not complete yet —
    see the door-sensor state machine below.
  - Keyholder: PIN matches the keyholder cache → **no relay pulse**, log immediately, queue a
    `keyholder_access` event at its `authorized` stage, and suppress `UNEXPECTED_OPEN` for the
    next ~10s (§ Keyholder disarm PIN).
  - Neither → queue `access_denied`; the keypad UI only ever shows a generic "denied", never which
    specific reason.
- Lockout: 5 consecutive failures → 30–60s cooldown — applies the same way whether the failures
  were attendee-PIN or keyholder-PIN attempts, they share one failure counter.
- Relay: momentary pulse (3–5s), never held open for the session duration — attendee path only,
  the keyholder path never pulses it at all.
- Door position sensor (§ Door position sensing): progresses `attendee_access`/`keyholder_access`
  events through `door_open`/`door_closed` stages as the physical door is observed opening and
  closing, sounds a beeper if the door isn't closed in time — 20s for an attendee-opened door,
  10 minutes for a keyholder-opened one — and separately alarms on and queues an `unexpected_open`
  event for a door opened with nothing authorized.
- Event queue: durable, flash-backed ring buffer (see "Power-loss resilience during access"
  above) — survives a reset mid-access, not just across poll cycles — retried with backoff until
  the server acks each `event_id`'s current stage, with each stage advance re-sent even before a
  retry would otherwise be due.
- Heartbeat POST includes `firmware_version` (now `FIRMWARE_VERSION` as defined above, formatted
  per the new `YYYYMMDDXX` scheme rather than the placeholder `"1.2.0"` in the original doc).

## Open items

- Confirm the exact Ethernet chip/library path for the specific board SKU in hand (W5500 near-
  certain given S3 has no native EMAC, but confirm core-version support before writing net.cpp).
- Confirm whether ESP-IDF app rollback is actually armed on the pinned `arduino-esp32` core
  version + chosen partition table — test with a deliberately-broken build, don't assume.
- Exact SPIFFS partition size needed once real template assets exist — `min_spiffs.csv`'s
  default may need bumping via a custom partition table if the status (and later settings) pages
  grow beyond a trivial size, and the 64-slot pending-events file needs to fit alongside them.
- Credential cache staleness cutoff: currently the device keeps operating from the last-good
  cache indefinitely on sync failure. Should it eventually refuse all entries if it hasn't synced
  in, say, 24h (a cancelled/revoked credential could otherwise still work) — a real
  availability-vs-security tradeoff to decide explicitly, not assume either way.
- Pending-event queue overflow: 64 slots should never fill in practice, but decide what happens
  if an extreme outage does — evict-oldest-with-a-logged-warning is the proposed default here,
  confirm that's acceptable rather than, say, refusing further entries once full.
- The pin assignment table is a placeholder with no real board constraints behind it yet — revisit
  once the actual module/schematic is available, particularly whether it has octal PSRAM (rules
  out `GPIO26`–`GPIO37`) and which pins the W5500 is actually wired to if using an off-the-shelf
  "ESP32-S3-ETH" board rather than a bare module.
- What happens if a `door_closed` event genuinely never arrives — sensor failure, or someone props
  the door open indefinitely and walks off — isn't fully decided. The `ALARM` beeper and the
  `hardware`/`error` log entry are the only signals; there's no automatic server-side timeout that
  force-completes or flags the attendee row. Worth deciding whether a background sweep (e.g. "any
  `door_open`-stage event untouched since before today's session ended, treat as needing a manual
  look") is needed, or whether the log entry alone is enough given someone would be on-site to hear
  the beeper anyway.
- No stacking/queueing behaviour is defined for two different keyholders disarming in close
  succession (e.g. one types their PIN, then a second keyholder does too before the first opens
  the door) — current design just logs each `keyholder_pin_used` independently and doesn't try to
  attribute the eventual door-open to whichever keyholder "really" caused it. Only matters once
  more than one keyholder is realistically on-site at once; not designed further here.
