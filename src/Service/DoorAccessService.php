<?php

namespace App\Service;

use App\Entity\Attendee;
use App\Entity\User;
use App\Repository\AttendeeRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The self-access door PIN lifecycle — see door-access-spec.md. A booking on an Event with
 * isSelfAccess gets a 6-digit PIN valid for its session window (event/occurrence start–end,
 * plus a grace period); the attendee's own id doubles as the door credential_id, so there's no
 * separate credential table. Single door for now (door_id 1, hardcoded per the spec).
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

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly AttendeeRepository $attendeeRepository,
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
     * Applies a `credential_used` door event — idempotent: re-delivering the same event for an
     * already-used credential just leaves it as used rather than erroring, since the door queues
     * and retries events until acknowledged.
     */
    public function markUsedViaDoor(Attendee $attendee, \DateTimeImmutable $timestamp): void
    {
        if ($attendee->isCheckedIn()) {
            return;
        }

        $attendee->setCheckedInAt($timestamp);
        $attendee->setCheckedInBy(null);
        $attendee->setCheckedInMethod(Attendee::CHECKED_IN_DOOR_PIN);
        $attendee->setPinStatus(Attendee::PIN_STATUS_USED);
    }

    /** Manual reception check-in. @throws \InvalidArgumentException if already checked in (via either channel) */
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
     * in time. Same session window; the old PIN simply stops validating once regenerated.
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
     *
     * @return array<int, array{credential_id: int, pin: string, valid_from: \DateTimeImmutable, valid_until: \DateTimeImmutable, status: string}>
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
                'valid_from'    => $validFrom,
                'valid_until'   => $validUntil,
                'status'        => $attendee->getPinStatus(),
            ];
        }

        return $credentials;
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

            if (!$this->attendeeRepository->pinIsActive($pin)) {
                return $pin;
            }
        }

        throw new \RuntimeException('Could not generate a unique door PIN after 20 attempts.');
    }
}
