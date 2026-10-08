<?php

namespace App\Service;

use App\Entity\AccessCard;
use App\Entity\AccessEvent;
use App\Entity\Attendee;
use App\Entity\User;
use App\Repository\AccessCardRepository;
use App\Repository\AccessEventRepository;
use App\Repository\AttendeeRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Self-access door entry — see door-access-spec.md. A confirmed booking on an Event with
 * isSelfAccess lets the member's registered card open the door during its session window
 * (event/occurrence start–end, plus a grace period); the attendee's own id doubles as the door
 * credential_id, so there's no separate credential table. Single door for now (door_id 1,
 * hardcoded per the spec). Card-only: attendee PINs were removed along with the door keypad.
 *
 * Every door interaction — attendee card, keyholder disarm PIN, an unexpected open, or a denial —
 * is also recorded as an AccessEvent row (§ Access event log), keyed on the device's own
 * client-generated event_id and updated in place as its stage advances. That's the detailed log;
 * `attendee.checkedInAt`/`checkedInBy`/`checkedInMethod` stay the attendee's own "attendance
 * complete" summary field, set only once the matching event reaches door_closed.
 */
class DoorAccessService
{
    private const PIN_LENGTH = 6;

    /** How long before the session start a booking's credential starts working. */
    private const GRACE_BEFORE = 'PT5M';

    /** How long after the session end a booking's credential keeps working. */
    private const GRACE_AFTER = 'PT10M';

    /** How far ahead of "now" a door's credential sync looks for upcoming sessions. */
    private const SYNC_WINDOW = 'PT2H';

    /** How far back a card's last door tap can be and still be on the exit reader's list (§ Exit reader). */
    private const EXIT_CARD_LOOKBACK = 'P1Y';

    /** How long before an exit tap a check-in can be and still be the session that tap closes (§ Exit reader). */
    private const CHECKOUT_LOOKBACK = 'PT24H';

    public const SUPPORTED_DOOR_ID = 1;

    /** The only credential `status` the server now sends — the firmware only caches a credential whose status is "active". */
    private const CREDENTIAL_STATUS_ACTIVE = 'active';

    /**
     * Per-request/per-command cache of AccessEvent rows created but not yet flushed, keyed on
     * event_id. Needed because a batch can carry more than one stage for the same event_id before
     * anything is flushed — a fresh DB query wouldn't see an unflushed insert from earlier in the
     * same batch and would otherwise create (and then fail to insert) a second row for it.
     *
     * @var array<string, AccessEvent>
     */
    private array $pendingAccessEvents = [];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly AttendeeRepository $attendeeRepository,
        private readonly UserRepository $userRepository,
        private readonly AccessEventRepository $accessEventRepository,
        private readonly AccessCardRepository $accessCardRepository,
    ) {}

    /** The moment {attendee}'s door credential starts working — the session start, less the "before" grace. */
    public function computeValidFrom(Attendee $attendee): \DateTimeImmutable
    {
        return $this->sessionStart($attendee)->sub(new \DateInterval(self::GRACE_BEFORE));
    }

    /** The moment {attendee}'s door credential stops working — the session end, plus the "after" grace. */
    public function computeValidUntil(Attendee $attendee): \DateTimeImmutable
    {
        return $this->sessionEnd($attendee)->add(new \DateInterval(self::GRACE_AFTER));
    }

    /**
     * Applies one stage of an `attendee_access` event — idempotent and upsert-keyed on
     * {eventId}: a stage only ever advances (see AccessEvent::advanceStage()), so a retried or
     * reordered POST for a stage already applied is a harmless no-op.
     *
     * The credential is deliberately NOT single-use: a confirmed booking stays synced to the door
     * for its whole session window, so the same card grants entry any number of times during it —
     * someone stepping out and back in shouldn't get locked out after the first tap.
     * `checked_in_at` (set once, at `door_closed`) is the "did they ever show up" summary — that's
     * a one-time fact regardless of how many times the credential is later reused.
     */
    public function applyAttendeeAccessEvent(string $eventId, string $stage, Attendee $attendee, \DateTimeImmutable $timestamp, ?string $cardUid = null): void
    {
        $event = $this->upsertAccessEvent($eventId, AccessEvent::TYPE_ATTENDEE_ACCESS);
        $event->setAttendee($attendee);

        if ($cardUid !== null) {
            $event->setCard($cardUid, $this->accessCardRepository->findOneByUid($cardUid)?->getUser());
        }

        if (!$event->advanceStage($stage, $timestamp)) {
            return;
        }

        if ($stage === AccessEvent::STAGE_DOOR_CLOSED && !$attendee->isCheckedIn()) {
            $attendee->setCheckedInAt($timestamp);
            $attendee->setCheckedInBy(null);
            $attendee->setCheckedInMethod(Attendee::CHECKED_IN_DOOR_CARD);
        }
    }

    /** Applies one stage of a `keyholder_access` event — same upsert/stage rules as attendee_access, but there's no attendee row to update: the AccessEvent row is the complete record. */
    public function applyKeyholderAccessEvent(string $eventId, string $stage, User $keyholder, \DateTimeImmutable $timestamp): void
    {
        $event = $this->upsertAccessEvent($eventId, AccessEvent::TYPE_KEYHOLDER_ACCESS);
        $event->setKeyholderUser($keyholder);
        $event->advanceStage($stage, $timestamp);
    }

    /** Applies one stage of an `unexpected_open` event — a door observed opening with nothing authorized. No `authorized` stage exists for this type; it starts straight at `door_open`. */
    public function applyUnexpectedOpenEvent(string $eventId, string $stage, \DateTimeImmutable $timestamp): void
    {
        $event = $this->upsertAccessEvent($eventId, AccessEvent::TYPE_UNEXPECTED_OPEN);
        $event->advanceStage($stage, $timestamp);
    }

    /** Applies one stage of a `standing_access` event — same upsert/stage rules as attendee_access, but keyed on the tapped card rather than a booking (§ All-hours cards). {cardUid} always resolves to a card here (the device only ever reports this type after a local all-hours match), so its user is looked up and stored the same way a denied/attendee card tap already does. */
    public function applyStandingAccessEvent(string $eventId, string $stage, string $cardUid, \DateTimeImmutable $timestamp): void
    {
        $event = $this->upsertAccessEvent($eventId, AccessEvent::TYPE_STANDING_ACCESS);
        $event->setCard($cardUid, $this->accessCardRepository->findOneByUid($cardUid)?->getUser());
        $event->advanceStage($stage, $timestamp);
    }

    /**
     * Records a denied attempt — single-stage, no progression. {attendee} is null if the PIN/card
     * didn't resolve to anything at all — but a denied *card* tap still carries {cardUid}, and if
     * that UID is registered to a member, this still identifies who tried even though they had no
     * valid booking to grant them entry (the whole point: tracking denied-but-identifiable taps).
     */
    public function applyAccessDenied(string $eventId, ?Attendee $attendee, ?string $reason, \DateTimeImmutable $timestamp, ?string $cardUid = null): void
    {
        $event = $this->upsertAccessEvent($eventId, AccessEvent::TYPE_ACCESS_DENIED);
        $event->setAttendee($attendee);
        $event->setDeniedReason($reason);
        $event->recordDeniedAt($timestamp);

        if ($cardUid !== null) {
            $event->setCard($cardUid, $this->accessCardRepository->findOneByUid($cardUid)?->getUser());
        }
    }

    /** Manual reception check-in. No AccessEvent is created for this — there's no door hardware involved, just a staff member confirming attendance directly. @throws \InvalidArgumentException if already checked in (via either channel) */
    public function checkInManually(Attendee $attendee, User $staff): void
    {
        if ($attendee->isCheckedIn()) {
            throw new \InvalidArgumentException('This attendee is already checked in.');
        }

        $attendee->setCheckedInAt(new \DateTimeImmutable());
        $attendee->setCheckedInBy($staff);
        $attendee->setCheckedInMethod(Attendee::CHECKED_IN_MANUAL);
    }

    /**
     * The active + near-future credentials a door should hold right now — an authoritative list
     * the device replaces its whole local cache with on every sync (see spec's firmware notes).
     * One per confirmed booking on a self-access event, carrying the member's active `card_uid`
     * (§ Card-based entry) — null if they have no usable card, which the firmware then skips.
     *
     * @return array<int, array{credential_id: int, card_uid: ?string, valid_from: \DateTimeImmutable, valid_until: \DateTimeImmutable, status: string}>
     */
    public function findCredentialsForDoor(int $doorId, \DateTimeImmutable $now): array
    {
        if ($doorId !== self::SUPPORTED_DOOR_ID) {
            return [];
        }

        $syncHorizon = $now->add(new \DateInterval(self::SYNC_WINDOW));
        $credentials = [];

        foreach ($this->attendeeRepository->findConfirmedSelfAccessAttendees() as $attendee) {
            $validFrom  = $this->computeValidFrom($attendee);
            $validUntil = $this->computeValidUntil($attendee);

            // Not yet within the sync horizon, or already fully lapsed — not relevant to this poll.
            if ($validFrom > $syncHorizon || $validUntil < $now) {
                continue;
            }

            $credentials[] = [
                'credential_id' => $attendee->getId(),
                'card_uid'      => $this->accessCardRepository->findActiveForUser($attendee->getUser())?->getUid(),
                'valid_from'    => $validFrom,
                'valid_until'   => $validUntil,
                'status'        => self::CREDENTIAL_STATUS_ACTIVE,
            ];
        }

        return $credentials;
    }

    /**
     * The keyholder disarm PINs a door should hold right now — same "authoritative full replace"
     * treatment as findCredentialsForDoor(), synced on the same poll (see § Keyholder disarm PIN).
     * No valid_from/valid_until: a standing credential, not a per-booking one.
     *
     * @return array<int, array{user_id: int, pin: string}>
     */
    public function findKeyholdersForDoor(int $doorId): array
    {
        if ($doorId !== self::SUPPORTED_DOOR_ID) {
            return [];
        }

        return array_map(
            static fn(User $user) => ['user_id' => $user->getId(), 'pin' => $user->getKeyholderPin()],
            $this->userRepository->findKeyholders(),
        );
    }

    /**
     * The all-hours card UIDs a door should hold right now — same "authoritative full replace"
     * treatment as findCredentialsForDoor()/findKeyholdersForDoor() (§ All-hours cards). Unlike a
     * keyholder PIN, tapping one of these DOES pulse the relay — it's a standing door credential,
     * not a disarm-only one. No valid_from/valid_until, same reasoning as keyholders.
     *
     * @return array<int, array{card_uid: string, user_id: int}>
     */
    public function findStandingCardsForDoor(int $doorId): array
    {
        if ($doorId !== self::SUPPORTED_DOOR_ID) {
            return [];
        }

        return array_map(
            static fn(AccessCard $card) => ['card_uid' => $card->getUid(), 'user_id' => $card->getUser()->getId()],
            $this->accessCardRepository->findAllHoursForDoor(),
        );
    }

    /**
     * The card UIDs the door's exit reader should accept right now — every active card tapped at
     * the door in the last year (§ Exit reader). Same "authoritative full replace" treatment as the
     * other lists. Not a gate: a card here only opens the door from the inside and identifies who
     * left; whether that closes a session is decided when the member_exit event arrives.
     *
     * @return array<int, array{user_id: int, card_uid: string}>
     */
    public function findExitCardsForDoor(int $doorId, \DateTimeImmutable $now): array
    {
        if ($doorId !== self::SUPPORTED_DOOR_ID) {
            return [];
        }

        return array_map(
            static fn(AccessCard $card) => ['user_id' => $card->getUser()->getId(), 'card_uid' => $card->getUid()],
            $this->accessCardRepository->findActiveUsedSince($now->sub(new \DateInterval(self::EXIT_CARD_LOOKBACK))),
        );
    }

    /**
     * Applies one stage of a `member_exit` event (§ Exit reader) — same upsert/stage rules as the
     * other types. The first time this event_id is seen (whichever stage arrives first), the
     * card's member is checked out of their session (see findSessionForExit()) — at
     * {authorizedAt}, the tap itself, not the door closing. Members can step out and back in
     * during a session, so a later exit tap moves the checkout time forward (checked_in_at stays
     * the first entry). Anyone else (no booking, never checked in, session already over and
     * closed) just gets the door-opening logged.
     */
    public function applyMemberExitEvent(string $eventId, string $stage, string $cardUid, \DateTimeImmutable $timestamp, \DateTimeImmutable $authorizedAt): void
    {
        $event = $this->upsertAccessEvent($eventId, AccessEvent::TYPE_MEMBER_EXIT);
        $user = $this->accessCardRepository->findOneByUid($cardUid)?->getUser();
        $event->setCard($cardUid, $user);

        $firstSeen = $event->getStage() === null;
        if (!$event->advanceStage($stage, $timestamp) || !$firstSeen || $user === null) {
            return;
        }

        $attendee = $this->findSessionForExit($user, $authorizedAt);
        if ($attendee === null) {
            return;
        }

        $attendee->checkOut($authorizedAt, Attendee::CHECKED_OUT_DOOR_CARD);
        $event->setAttendee($attendee);
    }

    /**
     * The booking an exit tap at {exitAt} belongs to, so that one session's taps never land on
     * another booking the same member has that day (e.g. an open staffed-hours check-in that was
     * never closed, then a separate evening self-access booking):
     *
     * 1. A checked-in booking whose session window (incl. grace) covers the exit — their current
     *    session. Already checked out is fine: that's a re-entry, and the checkout moves forward.
     * 2. Otherwise, if the exit falls in the window of another confirmed booking they never got
     *    checked into (e.g. they followed someone else in), it belongs to that session — nothing
     *    is checked out rather than closing an earlier, unrelated one.
     * 3. Otherwise it's an overstay: their most recent check-in within CHECKOUT_LOOKBACK, if still
     *    open. Never an older one — an exit after a closed session mustn't reach back past it.
     */
    private function findSessionForExit(User $user, \DateTimeImmutable $exitAt): ?Attendee
    {
        $since = $exitAt->sub(new \DateInterval(self::CHECKOUT_LOOKBACK));
        $checkIns = $this->attendeeRepository->findRecentCheckInsForUser($user, $since);

        foreach ($checkIns as $attendee) {
            if ($this->isWithinSessionWindow($attendee, $exitAt)) {
                return $attendee;
            }
        }

        foreach ($this->attendeeRepository->findConfirmedForUserFrom($user, $since) as $attendee) {
            if ($this->isWithinSessionWindow($attendee, $exitAt)) {
                return null;
            }
        }

        $latest = $checkIns[0] ?? null;

        return $latest !== null && !$latest->isCheckedOut() ? $latest : null;
    }

    private function isWithinSessionWindow(Attendee $attendee, \DateTimeImmutable $at): bool
    {
        return $at >= $this->computeValidFrom($attendee) && $at <= $this->computeValidUntil($attendee);
    }

    /** Generates a 6-digit keyholder PIN not already held by another keyholder. */
    public function generateUniqueKeyholderPin(?int $excludeUserId = null): string
    {
        for ($i = 0; $i < 20; $i++) {
            $pin = str_pad((string) random_int(0, 10 ** self::PIN_LENGTH - 1), self::PIN_LENGTH, '0', STR_PAD_LEFT);

            if (!$this->userRepository->keyholderPinExists($pin, $excludeUserId)) {
                return $pin;
            }
        }

        throw new \RuntimeException('Could not generate a unique keyholder PIN after 20 attempts.');
    }

    private function upsertAccessEvent(string $eventId, string $type): AccessEvent
    {
        if (isset($this->pendingAccessEvents[$eventId])) {
            return $this->pendingAccessEvents[$eventId];
        }

        $event = $this->accessEventRepository->findOneByEventId($eventId);

        if ($event === null) {
            $event = new AccessEvent($eventId, $type);
            $this->em->persist($event);
        }

        $this->pendingAccessEvents[$eventId] = $event;

        return $event;
    }

    private function sessionStart(Attendee $attendee): \DateTimeImmutable
    {
        return $this->combine($attendee, $attendee->getEvent()->getTimeFrom());
    }

    private function sessionEnd(Attendee $attendee): \DateTimeImmutable
    {
        return $this->combine($attendee, $attendee->getEvent()->getTimeTo());
    }

    /**
     * This booking's own occurrence date (or the event's own date for a one-off) combined with a
     * "HH:MM" time via Event::combineDateAndTime() — see there for the UK-wall-clock/DST handling.
     * Deliberately keyed on THIS booking's occurrence date, not the recurring event's base `date`
     * — see Event::combineDateAndTime()'s docblock for why that distinction matters across a DST
     * change. Verified against both 2026/27 UK transitions: a 19:00–21:00 local session converts
     * to 18:00–20:00Z in BST and 19:00–21:00Z in GMT, and an occurrence the week before/after a
     * transition correctly picks up the new offset on its own.
     */
    private function combine(Attendee $attendee, string $time): \DateTimeImmutable
    {
        $date = $attendee->getOccurrenceDate() ?? $attendee->getEvent()->getDate();

        return $attendee->getEvent()->combineDateAndTime($date, $time);
    }
}
