<?php

namespace App\Controller;

use App\Entity\Attendee;
use App\Entity\Event;
use App\Entity\Note;
use App\Entity\User;
use App\Repository\AttendeeRepository;
use App\Repository\EventRepository;
use App\Repository\NoteRepository;
use App\Repository\UserRepository;
use App\Service\BookingMailer;
use App\Service\BookingService;
use App\Service\DoorAccessService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/admin/bookings')]
#[IsGranted('ROLE_TEAM')]
class AdminBookingController extends AbstractController
{
    #[Route('', name: 'app_admin_bookings')]
    public function index(Request $request, AttendeeRepository $attendeeRepository): Response
    {
        $query     = trim($request->query->get('q', ''));
        $attendees = $attendeeRepository->search($query);

        if ($request->isXmlHttpRequest()) {
            return $this->render('admin/bookings/_list.html.twig', [
                'attendees' => $attendees,
            ]);
        }

        return $this->render('admin/bookings/index.html.twig', [
            'attendees'    => $attendees,
            'currentQuery' => $query,
        ]);
    }

    /** Always reached with a userId in the GET — from a member's contact page. There's no member search here; check in someone else by starting from their own contact page. */
    #[Route('/new', name: 'app_admin_booking_new', methods: ['GET', 'POST'])]
    public function new(
        Request $request,
        EventRepository $eventRepository,
        UserRepository $userRepository,
        AttendeeRepository $attendeeRepository,
        BookingMailer $bookingMailer,
        BookingService $bookingService,
    ): Response {
        $userId = (int) ($request->query->get('userId') ?: $request->request->get('userId', 0));
        $user   = $userId ? $userRepository->find($userId) : null;

        if (!$user instanceof User) {
            $this->addFlash('error', 'Choose a member to check in from their contact page.');
            return $this->redirectToRoute('app_admin_bookings');
        }

        $error = null;

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('admin_booking_new', $request->request->get('_csrf_token'))) {
                $this->addFlash('error', 'Access denied.');
                return $this->redirectToRoute('app_home');
            }

            $event = $eventRepository->find((int) $request->request->get('eventId'));

            if (!$event) {
                $error = 'Please select an event.';
            }

            // Only events that don't accept a ticket are checked in directly here — a ticket-accepting
            // event is booked by selling one instead (via the shop/cart), so this closes off a way to
            // bypass that sale even if the dropdown (already filtered) were tampered with.
            if (!$error && $event->acceptsTicket()) {
                $error = 'This event is sold via tickets — check members in by selling a ticket instead.';
            }

            $occurrenceDate = null;

            if (!$error) {
                if ($event->isRecurring()) {
                    $dateRaw = trim($request->request->get('occurrenceDate', ''));
                    if ($dateRaw === '') {
                        $error = 'Please choose a date for this recurring event.';
                    } else {
                        try {
                            $occurrenceDate = new \DateTimeImmutable($dateRaw);
                        } catch (\Exception) {
                            $error = 'Please enter a valid date.';
                        }
                        if (!$error && !$event->isValidForDate($occurrenceDate)) {
                            $error = 'That date is not a valid occurrence of this event.';
                        }
                    }
                } else {
                    $occurrenceDate = $event->getDate();
                }
            }

            if (!$error) {
                $status = $request->request->get('status', Attendee::STATUS_CONFIRMED);
                if (!in_array($status, [Attendee::STATUS_CONFIRMED, Attendee::STATUS_PENDING], true)) {
                    $status = Attendee::STATUS_CONFIRMED;
                }

                /** @var User $admin */
                $admin = $this->getUser();

                // Checking someone in for a session that's already running (or about to, within
                // 15 minutes) is a real, right-now attendance — stamp it as such. A session safely
                // in the future is just a booking/reservation; it isn't attended yet.
                $result = $bookingService->createBooking(
                    $event,
                    $user,
                    $occurrenceDate,
                    status: $status,
                    addedBy: $admin,
                    checkInNow: $this->isCheckInWindow($event, $occurrenceDate),
                );

                if (is_string($result)) {
                    $error = $result;
                } else {
                    if ($request->request->has('sendEmail') && $user->getEmail()) {
                        $bookingMailer->sendBookingConfirmation($user, $event, $occurrenceDate, $result->getPin());
                    }

                    $this->addFlash('success', 'Member checked in.');
                    return $this->redirectToRoute('app_admin_user_show', ['id' => $user->getId()]);
                }
            }
        }

        $selectedEventId = (int) $request->query->get('eventId', $request->request->get('eventId', 0));

        $today     = new \DateTimeImmutable('today');
        $weekStart = $today->modify('monday this week');
        $weekEnd   = $weekStart->modify('+6 days');

        $weekEvents = $eventRepository->findWithoutTicketAccessOverlapping($weekStart, $weekEnd);

        // Batch-load this week's bookings once, then derive per-occurrence counts/booked-state in
        // memory — same approach as the public calendar, avoids a query per occurrence shown.
        $eventIds        = array_map(static fn ($e) => $e->getId(), $weekEvents);
        $activeAttendees = $attendeeRepository->findActiveForEventsInRange($eventIds, $weekStart, $weekEnd);

        $occurrenceStats = [];
        foreach ($activeAttendees as $attendee) {
            $occDate = $attendee->getOccurrenceDate() ?? $attendee->getEvent()->getDate();
            $key     = $attendee->getEvent()->getId() . ':' . $occDate->format('Y-m-d');
            $occurrenceStats[$key] ??= ['count' => 0, 'bookedByUser' => false];
            $occurrenceStats[$key]['count']++;

            if ($attendee->getUser()->getId() === $user->getId()) {
                $occurrenceStats[$key]['bookedByUser'] = true;
            }
        }

        $days   = [];
        $period = new \DatePeriod($weekStart, new \DateInterval('P1D'), $weekEnd->modify('+1 day'));
        foreach ($period as $day) {
            $dayOccurrences = [];

            // Don't show historic events — only today's and this week's remaining occurrences.
            if ($day >= $today) {
                foreach ($weekEvents as $weekEvent) {
                    if (!$weekEvent->isValidForDate($day)) {
                        continue;
                    }

                    $stats     = $occurrenceStats[$weekEvent->getId() . ':' . $day->format('Y-m-d')] ?? ['count' => 0, 'bookedByUser' => false];
                    $spotsLeft = $weekEvent->getMaxAttendees() !== null ? max(0, $weekEvent->getMaxAttendees() - $stats['count']) : null;

                    $dayOccurrences[] = [
                        'event'           => $weekEvent,
                        'date'            => $day,
                        'spotsLeft'       => $spotsLeft,
                        'isFull'          => $spotsLeft !== null && $spotsLeft <= 0,
                        'bookedByUser'    => $stats['bookedByUser'],
                        'isRestricted'    => !$weekEvent->allowsUser($user),
                        'isCheckInWindow' => $this->isCheckInWindow($weekEvent, $day),
                        'accessMessage'   => $this->accessBlockedMessage($weekEvent, $user),
                    ];
                }

                usort($dayOccurrences, static fn (array $a, array $b) => $a['event']->getTimeFrom() <=> $b['event']->getTimeFrom());
            }

            $days[] = ['date' => $day, 'events' => $dayOccurrences];
        }

        return $this->render('admin/bookings/new.html.twig', [
            'error'           => $error,
            'days'            => $days,
            'weekStart'       => $weekStart,
            'weekEnd'         => $weekEnd,
            'today'           => $today,
            'selectedEventId' => $selectedEventId,
            'selectedMember'  => $user,
        ]);
    }

    #[Route('/{id}/edit', name: 'app_admin_booking_edit', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function edit(Request $request, Attendee $attendee, NoteRepository $noteRepository, DoorAccessService $doorAccessService, BookingService $bookingService): Response
    {
        $error = null;

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('admin_booking_edit_' . $attendee->getId(), $request->request->get('_csrf_token'))) {
                $this->addFlash('error', 'Access denied.');
                return $this->redirectToRoute('app_admin_user_show', ['id' => $attendee->getUser()->getId()]);
            }

            $status = $request->request->get('status', Attendee::STATUS_CONFIRMED);
            if (!in_array($status, [Attendee::STATUS_CONFIRMED, Attendee::STATUS_PENDING, Attendee::STATUS_WAITING, Attendee::STATUS_CANCELLED], true)) {
                $error = 'Please choose a valid status.';
            }

            if (!$error) {
                if ($status === Attendee::STATUS_CANCELLED) {
                    $bookingService->cancelBooking($attendee);
                } else {
                    $error = $bookingService->reinstateBooking($attendee, $status);
                }

                if (!$error) {
                    $this->addFlash('success', 'Booking updated.');
                    return $this->redirectToRoute('app_admin_user_show', ['id' => $attendee->getUser()->getId()]);
                }
            }
        }

        return $this->render('admin/bookings/edit.html.twig', [
            'attendee'   => $attendee,
            'error'      => $error,
            'notes'      => $noteRepository->findForNoteable(Note::TYPE_ATTENDEE, $attendee->getId()),
            'validFrom'  => $attendee->getEvent()->isSelfAccess() ? $doorAccessService->computeValidFrom($attendee) : null,
            'validUntil' => $attendee->getEvent()->isSelfAccess() ? $doorAccessService->computeValidUntil($attendee) : null,
        ]);
    }

    #[Route('/{id}/staffing/approve', name: 'app_admin_booking_staffing_approve', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function approveStaffing(Request $request, Attendee $attendee, EntityManagerInterface $em): Response
    {
        return $this->setStaffingStatus($request, $attendee, $em, Attendee::STAFFING_APPROVED, 'Member approved as on duty.');
    }

    #[Route('/{id}/staffing/decline', name: 'app_admin_booking_staffing_decline', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function declineStaffing(Request $request, Attendee $attendee, EntityManagerInterface $em): Response
    {
        return $this->setStaffingStatus($request, $attendee, $em, Attendee::STAFFING_DECLINED, 'Staffing request declined.');
    }

    /** Clears the staffing designation entirely — the booking itself is left untouched. */
    #[Route('/{id}/staffing/remove', name: 'app_admin_booking_staffing_remove', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function removeStaffing(Request $request, Attendee $attendee, EntityManagerInterface $em): Response
    {
        if (!$this->isCsrfTokenValid('admin_booking_staffing_' . $attendee->getId(), $request->request->get('_csrf_token'))) {
            $this->addFlash('error', 'Access denied.');
            return $this->redirectToRoute('app_home');
        }

        $attendee->setStaffingRequirement(null);
        $attendee->setStaffingStatus(null);
        $em->flush();

        $this->addFlash('success', 'Staffing designation removed.');
        return $this->redirectToEventShow($attendee);
    }

    private function setStaffingStatus(Request $request, Attendee $attendee, EntityManagerInterface $em, string $status, string $successMessage): Response
    {
        if (!$this->isCsrfTokenValid('admin_booking_staffing_' . $attendee->getId(), $request->request->get('_csrf_token'))) {
            $this->addFlash('error', 'Access denied.');
            return $this->redirectToRoute('app_home');
        }

        if (!$attendee->isStaffing()) {
            $this->addFlash('error', 'This booking is not a staffing request.');
            return $this->redirectToEventShow($attendee);
        }

        $attendee->setStaffingStatus($status);
        $em->flush();

        $this->addFlash('success', $successMessage);
        return $this->redirectToEventShow($attendee);
    }

    /**
     * Why {user} can't be checked in for free onto {event} via its accepted access methods, or
     * null if they can (or the event doesn't gate on credit/membership at all). A ticket-accepting
     * event never reaches here — findWithoutTicketAccessOverlapping() already excludes those, since
     * they're checked in by selling a ticket instead.
     */
    private function accessBlockedMessage(Event $event, User $user): ?string
    {
        if (!$event->hasAccessRestriction() || $user->canCoverMembershipOrCreditBooking($event)) {
            return null;
        }

        return match (true) {
            $event->acceptsCredit() && $event->acceptsMembership() => 'Needs credit or membership',
            $event->acceptsCredit()                                => 'No credit',
            default                                                => 'No membership',
        };
    }

    /** Whether {date}'s occurrence of {event} is already running, or about to start within 15 minutes — the threshold for treating a check-in as a real, right-now attendance rather than a future booking. Also true once the session has ended; there's no "too late" cutoff here, only "too early". */
    private function isCheckInWindow(Event $event, \DateTimeImmutable $date): bool
    {
        $sessionStart = $event->combineDateAndTime($date, $event->getTimeFrom());

        return new \DateTimeImmutable() >= $sessionStart->sub(new \DateInterval('PT15M'));
    }

    private function redirectToEventShow(Attendee $attendee): Response
    {
        $showParams = ['id' => $attendee->getEvent()->getId()];
        if ($attendee->getOccurrenceDate()) {
            $showParams['date'] = $attendee->getOccurrenceDate()->format('Y-m-d');
        }
        return $this->redirectToRoute('app_admin_event_show', $showParams);
    }

    #[Route('/{id}/delete', name: 'app_admin_booking_delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function delete(Request $request, Attendee $attendee, EntityManagerInterface $em, NoteRepository $noteRepository): Response
    {
        $userId = $attendee->getUser()->getId();

        if (!$this->isCsrfTokenValid('delete_booking_' . $attendee->getId(), $request->request->get('_csrf_token'))) {
            $this->addFlash('error', 'Access denied.');
            return $this->redirectToRoute('app_admin_user_show', ['id' => $userId]);
        }

        if ($attendee->getPaidAmount() !== '0.00') {
            $this->addFlash('error', 'Cannot delete a booking with a paid amount recorded. Set the paid amount to £0 first.');
            return $this->redirectToRoute('app_admin_booking_edit', ['id' => $attendee->getId()]);
        }

        $pinnedCount = $noteRepository->countPinnedFor(Note::TYPE_ATTENDEE, $attendee->getId());
        if ($pinnedCount > 0) {
            $this->addFlash('error', "Unpin {$pinnedCount} pinned note(s) before deleting this record.");
            return $this->redirectToRoute('app_admin_booking_edit', ['id' => $attendee->getId()]);
        }

        foreach ($noteRepository->findForNoteable(Note::TYPE_ATTENDEE, $attendee->getId()) as $note) {
            $em->remove($note);
        }

        $em->remove($attendee);
        $em->flush();

        $this->addFlash('success', 'Booking deleted.');
        return $this->redirectToRoute('app_admin_user_show', ['id' => $userId]);
    }

}
