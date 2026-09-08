<?php

namespace App\Service;

use App\Entity\Attendee;
use App\Entity\Event;
use App\Entity\EventStaffingRequirement;
use App\Entity\User;
use App\Repository\AttendeeRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The shared shape behind every place a booking onto a non-ticketed Event gets created directly
 * (as opposed to being fulfilled from a paid ticket sale — see EventTicketFulfilmentHandler,
 * which is a genuinely different flow with its own eligibility checks already done at cart-add
 * time): the public self-serve "book now" button, guest booking, and admin check-in. Centralised
 * here so the eligibility/credit/PIN sequence — and any future fix to it — only lives once.
 */
class BookingService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly AttendeeRepository $attendeeRepository,
        private readonly EventBookingCreditService $eventBookingCreditService,
        private readonly DoorAccessService $doorAccessService,
    ) {}

    /**
     * Validates and creates a booking, returning the new Attendee or an error message.
     *
     * @param User $user The member being booked onto the event.
     * @param \DateTimeImmutable $occurrenceDate The specific occurrence being booked — caller's
     *        responsibility to have already resolved and validated this (e.g. that it's a real
     *        occurrence of a recurring event, and not in the past for a self-serve booking).
     * @param string $status Attendee::STATUS_CONFIRMED or ::STATUS_PENDING — self-serve bookings
     *        are always confirmed; only the admin check-in screen offers pending.
     * @param ?User $addedBy Set when an admin is creating this booking on the member's behalf
     *        (drives Attendee::addedBy); null for a self-serve booking.
     * @param bool $checkInNow Stamps checkedInAt/checkedInBy/checkedInMethod immediately — for
     *        the admin check-in screen's "this session is running (or about to)" case. Requires
     *        $addedBy, since a self-serve booking is never a staff-witnessed attendance.
     * @param ?EventStaffingRequirement $staffingRequirement Self-serve-only: a staffing slot the
     *        member is simultaneously volunteering to fill alongside their own booking.
     */
    public function createBooking(
        Event $event,
        User $user,
        \DateTimeImmutable $occurrenceDate,
        string $status = Attendee::STATUS_CONFIRMED,
        ?User $addedBy = null,
        bool $checkInNow = false,
        ?EventStaffingRequirement $staffingRequirement = null,
    ): Attendee|string {
        if (!$event->allowsUser($user)) {
            return 'This member does not hold the certification required for this event.';
        }

        if (!$event->getRestrictions()->isEmpty() && !$user->hasCompleteEmergencyContact()) {
            return 'This member has no emergency contact details on file — add them before booking onto a certification-restricted event.';
        }

        $storedOccurrenceDate = $event->isRecurring() ? $occurrenceDate : null;

        if ($this->attendeeRepository->findActiveBooking($event, $user, $storedOccurrenceDate)) {
            return 'This member is already booked onto this event.';
        }

        if ($event->getMaxAttendees() !== null
            && $this->attendeeRepository->countActiveForOccurrence($event, $storedOccurrenceDate) >= $event->getMaxAttendees()
        ) {
            return 'Sorry, this event is fully booked.';
        }

        try {
            $needsCredit = $this->eventBookingCreditService->requiresCredit($event, $user);
        } catch (\InvalidArgumentException $e) {
            return $e->getMessage();
        }

        $attendee = new Attendee();
        $attendee->setEvent($event);
        $attendee->setUser($user);
        $attendee->setOccurrenceDate($storedOccurrenceDate);
        $attendee->setStatus($status);
        $attendee->setAddedBy($addedBy);

        if ($staffingRequirement) {
            $attendee->setStaffingRequirement($staffingRequirement);
            // Skipping the pending-approval step for now — self-signups go straight to approved.
            // The approve/decline flow (AdminBookingController) is left in place to switch back to easily.
            $attendee->setStaffingStatus(Attendee::STAFFING_APPROVED);
        }

        if ($checkInNow) {
            $attendee->setCheckedInAt(new \DateTimeImmutable());
            $attendee->setCheckedInBy($addedBy);
            $attendee->setCheckedInMethod(Attendee::CHECKED_IN_MANUAL);
        }

        $this->em->persist($attendee);

        if ($needsCredit) {
            $this->eventBookingCreditService->spendCredit($user, $event, $attendee);
        }

        $this->doorAccessService->generatePinIfNeeded($attendee);

        $this->em->flush();

        return $attendee;
    }

    /** Cancels {attendee} and revokes any door PIN it holds. */
    public function cancelBooking(Attendee $attendee): void
    {
        $attendee->setStatus(Attendee::STATUS_CANCELLED);
        $this->doorAccessService->revokePin($attendee);
        $this->em->flush();
    }

    /** Moves {attendee} to {status} (confirmed/pending) — the other side of cancelBooking(), e.g. un-cancelling a booking. Issues a door PIN if the event is self-access and it doesn't already have an active one. */
    public function reinstateBooking(Attendee $attendee, string $status): void
    {
        $attendee->setStatus($status);
        $this->doorAccessService->generatePinIfNeeded($attendee);
        $this->em->flush();
    }
}
