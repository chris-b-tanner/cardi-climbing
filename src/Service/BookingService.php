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
        private readonly UserService $userService,
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
     * @param ?User $addedBy Set when a staff member is creating this booking on the member's
     *        behalf (drives Attendee::addedBy); null for a self-serve booking. When this user holds
     *        ROLE_ADMIN, a full event no longer blocks a confirmed/pending booking — admins are
     *        allowed to knowingly oversubscribe an event; anyone else still can't.
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

        if ($event->acceptsTicket() && !$event->acceptsCredit() && !$event->acceptsMembership()) {
            return 'This event is booked by purchasing a ticket, not booked directly.';
        }

        $storedOccurrenceDate = $event->isRecurring() ? $occurrenceDate : null;

        if ($this->attendeeRepository->findActiveBooking($event, $user, $storedOccurrenceDate)) {
            return 'This member is already booked onto this event.';
        }

        // A waiting-list booking is expected to exceed capacity — that's the point of it — and an
        // admin is allowed to knowingly oversubscribe an event, so only a confirmed/pending
        // booking made by a non-admin (self-serve, guest, or ordinary team member) is actually
        // blocked by a full event.
        $isAdmin = $addedBy !== null && in_array(User::ROLE_ADMIN, $addedBy->getRoles(), true);

        if ($status !== Attendee::STATUS_WAITING
            && !$isAdmin
            && $event->getMaxAttendees() !== null
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

    /** Cancels {attendee} and revokes any door PIN it holds. @param ?User $actor Set when a staff member (or the member themselves, self-service) is making this change — attributed on the status-change note. */
    public function cancelBooking(Attendee $attendee, ?User $actor = null): void
    {
        $previousStatus = $attendee->getStatus();

        $attendee->setStatus(Attendee::STATUS_CANCELLED);
        $this->doorAccessService->revokePin($attendee);
        $this->em->flush();

        $this->recordStatusChangeIfNeeded($attendee, $previousStatus, $actor);
    }

    /**
     * Moves {attendee} to {status} (confirmed/pending/waiting) — the other side of cancelBooking(),
     * e.g. un-cancelling a booking. Issues a door PIN if the event is self-access and it doesn't
     * already have an active one. Returns an error message instead of reinstating if doing so would
     * push a capped event over its max attendees — only checked when {attendee} is currently
     * cancelled (switching an already-active booking between statuses doesn't add a new seat) and
     * the target isn't "waiting" (which never claims a seat — that's the point of it).
     */
    /** @param ?User $actor Set when a staff member is making this change; an admin among them may reinstate into a full event (see createBooking()'s $addedBy doc for the same rule). */
    public function reinstateBooking(Attendee $attendee, string $status, ?User $actor = null): ?string
    {
        $event   = $attendee->getEvent();
        $isAdmin = $actor !== null && in_array(User::ROLE_ADMIN, $actor->getRoles(), true);

        if ($attendee->isCancelled()
            && $status !== Attendee::STATUS_WAITING
            && !$isAdmin
            && $event->getMaxAttendees() !== null
            && $this->attendeeRepository->countActiveForOccurrence($event, $attendee->getOccurrenceDate()) >= $event->getMaxAttendees()
        ) {
            return 'Sorry, this event is fully booked — there is no spare place to reinstate this booking into.';
        }

        $previousStatus = $attendee->getStatus();

        $attendee->setStatus($status);
        $this->doorAccessService->generatePinIfNeeded($attendee);
        $this->em->flush();

        $this->recordStatusChangeIfNeeded($attendee, $previousStatus, $actor);

        return null;
    }

    /**
     * Booking-status paper trail: records a Note on {attendee}'s member if its status actually
     * changed from {previousStatus} to whatever is currently set — call this after setStatus() (so
     * it logs the real new value) and only for a change on an *existing* attendee, not the initial
     * status set at booking creation (createBooking() deliberately doesn't call this).
     */
    public function recordStatusChangeIfNeeded(Attendee $attendee, string $previousStatus, ?User $actor = null): void
    {
        if ($attendee->getStatus() === $previousStatus) {
            return;
        }

        $event = $attendee->getEvent();
        $when  = $attendee->getOccurrenceDate() ? ' (' . $attendee->getOccurrenceDate()->format('d M Y') . ')' : '';

        $this->userService->addNote(
            $attendee->getUser(),
            sprintf(
                'Booking status changed from %s to %s for %s%s.',
                ucfirst($previousStatus),
                ucfirst($attendee->getStatus()),
                $event->getTitle(),
                $when,
            ),
            $actor,
        );
    }
}
