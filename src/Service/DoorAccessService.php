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
 * The self-access door PIN lifecycle — see door-access-spec.md. A booking on an Event with
 * isSelfAccess gets a 6-digit PIN valid for its session window (event/occurrence start–end,
 * plus a grace period); the attendee's own id doubles as the door credential_id, so there's no
 * separate credential table. Single door for now (door_id 1, hardcoded per the spec).
 *
 * Every door interaction — attendee PIN, keyholder disarm PIN, an unexpected open, or a denial —
 * is also recorded as an AccessEvent row (§ Access event log), keyed on the device's own
 * client-generated event_id and updated in place as its stage advances. That's the detailed log;
 * `attendee.checkedInAt`/`checkedInBy`/`checkedInMethod` stay the attendee's own "attendance
 * complete" summary field, set only once the matching event reaches door_closed.
 */
class DoorAccessService
{
    private const PIN_LENGTH = 6;

    /** How long before the session start a PIN starts working. */
    private const GRACE_BEFORE = 'PT5M';

    /** How long after the session end a PIN keeps working. */
    private const GRACE_AFTER = 'PT10M';

    /** How far ahead of "now" a door's credential sync looks for upcoming sessions. */
    private const SYNC_WINDOW = 'PT2H';

    public const SUPPORTED_DOOR_ID = 1;

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

    /** Issues a PIN for {attendee} if its event is self-access and it doesn't already have an active one. A no-op otherwise (e.g. a normal event, or a cancelled/pending booking). */
    public function generatePinIfNeeded(Attendee $attendee): void
    {
        if (!$attendee->getEvent()->isSelfAccess() || $attendee->getStatus() !== Attendee::STATUS_CONFIRMED) {
            return;
        }

        if ($attendee->isPinActive()) {
            return;
        }

        $attendee->setPin($this->generateUniquePin());
        $attendee->setPinStatus(Attendee::PIN_STATUS_ACTIVE);
    }

    /** Revokes {attendee}'s PIN (e.g. its booking was cancelled) — a no-op if it never had an active one. */
    public function revokePin(Attendee $attendee): void
    {
        if ($attendee->getPinStatus() === Attendee::PIN_STATUS_ACTIVE) {
            $attendee->setPinStatus(Attendee::PIN_STATUS_REVOKED);
        }
    }

    /** The moment {attendee}'s PIN starts working — the session start, less the "before" grace. */
    public function computeValidFrom(Attendee $attendee): \DateTimeImmutable
    {
        return $this->sessionStart($attendee)->sub(new \DateInterval(self::GRACE_BEFORE));
    }

    /** The moment {attendee}'s PIN stops working — the session end, plus the "after" grace. */
    public function computeValidUntil(Attendee $attendee): \DateTimeImmutable
    {
        return $this->sessionEnd($attendee)->add(new \DateInterval(self::GRACE_AFTER));
    }

    /** Whether {now} still falls within {attendee}'s session window (including grace) — regeneration is refused once this has fully lapsed. */
    public function isWithinGraceWindow(Attendee $attendee, \DateTimeImmutable $now): bool
    {
        return $now <= $this->computeValidUntil($attendee);
    }

    /**
     * Applies one stage of an `attendee_access` event — idempotent and upsert-keyed on
     * {eventId}: a stage only ever advances (see AccessEvent::advanceStage()), so a retried or
     * reordered POST for a stage already applied is a harmless no-op.
     *
     * The credential is deliberately NOT single-use: a PIN or card stays `active` (and therefore
     * synced to the door) for the attendee's whole session window, so the same PIN/card grants
     * entry any number of times during it — someone stepping out and back in, or a card that also
     * doubles as the exit-adjacent re-entry method, shouldn't get locked out after the first tap.
     * `checked_in_at` (set once, at `door_closed`) is the "did they ever show up" summary — that's
     * a one-time fact regardless of how many times the credential is later reused; `pin_status`
     * only ever changes via cancellation (`revokePin()`) or an explicit regenerate.
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
            $attendee->setCheckedInMethod(Attendee::CHECKED_IN_DOOR_PIN);
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

        if ($attendee->getPin() !== null) {
            $attendee->setPinStatus(Attendee::PIN_STATUS_USED);
        }
    }

    /**
     * Issues a fresh PIN for {attendee} — e.g. the door opened but the member didn't get through
     * in time. Same session window; the old PIN simply stops validating once regenerated. Doesn't
     * touch the old AccessEvent row — it stays as history of the stuck attempt.
     *
     * @throws \InvalidArgumentException if the session window (including grace) has already fully lapsed
     */
    public function regeneratePin(Attendee $attendee): string
    {
        if (!$this->isWithinGraceWindow($attendee, new \DateTimeImmutable())) {
            throw new \InvalidArgumentException('This booking\'s session window has already ended.');
        }

        $pin = $this->generateUniquePin();

        $attendee->setPin($pin);
        $attendee->setPinStatus(Attendee::PIN_STATUS_ACTIVE);
        $attendee->setCheckedInAt(null);
        $attendee->setCheckedInBy(null);
        $attendee->setCheckedInMethod(null);

        return $pin;
    }

    /**
     * The active + near-future credentials a door should hold right now — an authoritative list
     * the device replaces its whole local cache with on every sync (see spec's firmware notes).
     * `card_uid` rides alongside `pin` (§ Card-based entry) so a tap authenticates the same
     * attendee-credential row a PIN already represents — null if the member has no card registered.
     *
     * @return array<int, array{credential_id: int, pin: string, card_uid: ?string, valid_from: \DateTimeImmutable, valid_until: \DateTimeImmutable, status: string}>
     */
    public function findCredentialsForDoor(int $doorId, \DateTimeImmutable $now): array
    {
        if ($doorId !== self::SUPPORTED_DOOR_ID) {
            return [];
        }

        $syncHorizon = $now->add(new \DateInterval(self::SYNC_WINDOW));
        $credentials = [];

        foreach ($this->attendeeRepository->findActivePinAttendees() as $attendee) {
            $validFrom  = $this->computeValidFrom($attendee);
            $validUntil = $this->computeValidUntil($attendee);

            // Not yet within the sync horizon, or already fully lapsed — not relevant to this poll.
            if ($validFrom > $syncHorizon || $validUntil < $now) {
                continue;
            }

            $credentials[] = [
                'credential_id' => $attendee->getId(),
                'pin'           => $attendee->getPin(),
                'card_uid'      => $this->accessCardRepository->findActiveForUser($attendee->getUser())?->getUid(),
                'valid_from'    => $validFrom,
                'valid_until'   => $validUntil,
                'status'        => $attendee->getPinStatus(),
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

    /** Generates a unique 6-digit keyholder PIN, excluding both other active keyholder PINs and currently-active attendee PINs — the two pools must never collide (§ PIN lifecycle). */
    public function generateUniqueKeyholderPin(?int $excludeUserId = null): string
    {
        for ($i = 0; $i < 20; $i++) {
            $pin = str_pad((string) random_int(0, 10 ** self::PIN_LENGTH - 1), self::PIN_LENGTH, '0', STR_PAD_LEFT);

            if (!$this->attendeeRepository->pinIsActive($pin) && !$this->userRepository->keyholderPinExists($pin, $excludeUserId)) {
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

    private function generateUniquePin(): string
    {
        for ($i = 0; $i < 20; $i++) {
            $pin = str_pad((string) random_int(0, 10 ** self::PIN_LENGTH - 1), self::PIN_LENGTH, '0', STR_PAD_LEFT);

            if (!$this->attendeeRepository->pinIsActive($pin) && !$this->userRepository->keyholderPinExists($pin)) {
                return $pin;
            }
        }

        throw new \RuntimeException('Could not generate a unique door PIN after 20 attempts.');
    }
}
