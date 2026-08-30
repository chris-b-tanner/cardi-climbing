<?php

namespace App\Controller;

use App\Entity\Attendee;
use App\Entity\User;
use App\Repository\AttendeeRepository;
use App\Repository\EventRepository;
use App\Repository\UserRepository;
use App\Service\BookingMailer;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
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

    #[Route('/new', name: 'app_admin_booking_new', methods: ['GET', 'POST'])]
    public function new(
        Request $request,
        EntityManagerInterface $em,
        EventRepository $eventRepository,
        UserRepository $userRepository,
        AttendeeRepository $attendeeRepository,
        BookingMailer $bookingMailer,
    ): Response {
        $error = null;
        $user  = null;

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('admin_booking_new', $request->request->get('_csrf_token'))) {
                $this->addFlash('error', 'Access denied.');
                return $this->redirectToRoute('app_home');
            }

            $event = $eventRepository->find((int) $request->request->get('eventId'));
            $user  = $userRepository->find((int) $request->request->get('userId'));

            if (!$event || !$user) {
                $error = 'Please select both an event and a member.';
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

            $storedOccurrenceDate = (!$error && $event->isRecurring()) ? $occurrenceDate : null;

            if (!$error && $attendeeRepository->findActiveBooking($event, $user, $storedOccurrenceDate)) {
                $error = 'This member is already booked onto this event.';
            }

            if (!$error && !$event->allowsUser($user)) {
                $error = 'This member does not hold the certification required for this event.';
            }

            if (!$error
                && $event->getMaxAttendees() !== null
                && $attendeeRepository->countActiveForOccurrence($event, $storedOccurrenceDate) >= $event->getMaxAttendees()
            ) {
                $error = 'This event is full.';
            }

            if (!$error) {
                $status = $request->request->get('status', Attendee::STATUS_CONFIRMED);
                if (!in_array($status, [Attendee::STATUS_CONFIRMED, Attendee::STATUS_PENDING], true)) {
                    $status = Attendee::STATUS_CONFIRMED;
                }

                $priceRaw = trim($request->request->get('price', ''));
                $paidRaw  = trim($request->request->get('paidAmount', ''));

                /** @var User $admin */
                $admin = $this->getUser();

                $attendee = new Attendee();
                $attendee->setEvent($event);
                $attendee->setUser($user);
                $attendee->setOccurrenceDate($storedOccurrenceDate);
                $attendee->setStatus($status);
                $attendee->setPrice($priceRaw !== '' ? number_format((float) $priceRaw, 2, '.', '') : null);
                $attendee->setPaidAmount($paidRaw !== '' ? number_format((float) $paidRaw, 2, '.', '') : '0.00');
                $attendee->setAddedBy($admin);

                $em->persist($attendee);
                $em->flush();

                if ($request->request->has('sendEmail') && $user->getEmail()) {
                    $bookingMailer->sendBookingConfirmation($user, $event, $occurrenceDate);
                }

                $this->addFlash('success', 'Booking created.');
                $showParams = ['id' => $event->getId()];
                if ($storedOccurrenceDate) {
                    $showParams['date'] = $storedOccurrenceDate->format('Y-m-d');
                }
                return $this->redirectToRoute('app_admin_event_show', $showParams);
            }
        }

        $selectedEventId = (int) $request->query->get('eventId', $request->request->get('eventId', 0));

        if ($user === null) {
            $userId = (int) $request->query->get('userId', 0);
            if ($userId) {
                $user = $userRepository->find($userId);
            }
        }

        return $this->render('admin/bookings/new.html.twig', [
            'error'           => $error,
            'events'          => $eventRepository->findAllOrdered(),
            'selectedEventId' => $selectedEventId,
            'selectedMember'  => $user,
        ]);
    }

    #[Route('/{id}/edit', name: 'app_admin_booking_edit', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function edit(Request $request, Attendee $attendee, EntityManagerInterface $em): Response
    {
        $error = null;

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('admin_booking_edit_' . $attendee->getId(), $request->request->get('_csrf_token'))) {
                $this->addFlash('error', 'Access denied.');
                return $this->redirectToRoute('app_admin_user_show', ['id' => $attendee->getUser()->getId()]);
            }

            $status = $request->request->get('status', Attendee::STATUS_CONFIRMED);
            if (!in_array($status, [Attendee::STATUS_CONFIRMED, Attendee::STATUS_PENDING, Attendee::STATUS_CANCELLED], true)) {
                $error = 'Please choose a valid status.';
            }

            if (!$error) {
                $priceRaw = trim($request->request->get('price', ''));
                $paidRaw  = trim($request->request->get('paidAmount', ''));

                $attendee->setStatus($status);
                $attendee->setPrice($priceRaw !== '' ? number_format((float) $priceRaw, 2, '.', '') : null);
                $attendee->setPaidAmount($paidRaw !== '' ? number_format((float) $paidRaw, 2, '.', '') : '0.00');

                $em->flush();

                $this->addFlash('success', 'Booking updated.');
                return $this->redirectToRoute('app_admin_user_show', ['id' => $attendee->getUser()->getId()]);
            }
        }

        return $this->render('admin/bookings/edit.html.twig', [
            'attendee' => $attendee,
            'error'    => $error,
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

    private function redirectToEventShow(Attendee $attendee): Response
    {
        $showParams = ['id' => $attendee->getEvent()->getId()];
        if ($attendee->getOccurrenceDate()) {
            $showParams['date'] = $attendee->getOccurrenceDate()->format('Y-m-d');
        }
        return $this->redirectToRoute('app_admin_event_show', $showParams);
    }

    #[Route('/{id}/delete', name: 'app_admin_booking_delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function delete(Request $request, Attendee $attendee, EntityManagerInterface $em): Response
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

        $em->remove($attendee);
        $em->flush();

        $this->addFlash('success', 'Booking deleted.');
        return $this->redirectToRoute('app_admin_user_show', ['id' => $userId]);
    }

    #[Route('/member-search', name: 'app_admin_booking_member_search')]
    public function memberSearch(Request $request, UserRepository $userRepository): JsonResponse
    {
        $query = trim($request->query->get('q', ''));

        if (mb_strlen($query) < 2) {
            return $this->json([]);
        }

        $members = $userRepository->search($query, null, 20);

        return $this->json(array_map(static function (User $user) {
            $displayName = trim(($user->getFirstName() ?? '') . ' ' . ($user->getLastName() ?? ''));
            $name        = $displayName ?: ($user->getEmail() ?: 'Member #' . $user->getId());

            return [
                'id'    => $user->getId(),
                'label' => $user->getEmail() ? $name . ' — ' . $user->getEmail() : $name,
            ];
        }, $members));
    }
}
