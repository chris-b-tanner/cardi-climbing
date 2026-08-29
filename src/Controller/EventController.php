<?php

namespace App\Controller;

use App\Entity\Attendee;
use App\Entity\Event;
use App\Entity\EventStaffingRequirement;
use App\Entity\Note;
use App\Entity\User;
use App\Repository\AttendeeRepository;
use App\Repository\EventRepository;
use App\Repository\UserRepository;
use App\Service\BookingMailer;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

class EventController extends AbstractController
{
    #[Route('/events', name: 'app_events')]
    public function index(Request $request, EventRepository $eventRepository, AttendeeRepository $attendeeRepository): Response
    {
        $year  = (int) $request->query->get('year', (int) date('Y'));
        $month = (int) $request->query->get('month', (int) date('n'));

        // Normalise any out-of-range month (e.g. from prev/next navigation) into its year.
        $year  += intdiv($month - 1, 12);
        $month = (($month - 1) % 12 + 12) % 12 + 1;

        $monthStart = new \DateTimeImmutable(sprintf('%04d-%02d-01', $year, $month));
        $monthEnd   = $monthStart->modify('last day of this month');

        $gridStart = $monthStart->modify('monday this week');
        $gridEnd   = $monthEnd->modify('sunday this week');

        $events = $eventRepository->findPublishedOverlapping($gridStart, $gridEnd, $this->isGranted('ROLE_TEAM'));

        /** @var User|null $user */
        $user  = $this->getUser();
        $today = new \DateTimeImmutable('today');

        // Fetch every booking for these events across the whole grid in one query, then
        // derive per-occurrence counts/booked-state from it in memory — avoids running a
        // count + booking-lookup query for every single occurrence shown on the calendar.
        $eventIds        = array_map(static fn (Event $e) => $e->getId(), $events);
        $activeAttendees = $attendeeRepository->findActiveForEventsInRange($eventIds, $gridStart, $gridEnd);

        $occurrenceStats = [];
        foreach ($activeAttendees as $attendee) {
            $key = $this->occurrenceKey($attendee->getEvent(), $attendee->getOccurrenceDate() ?? $attendee->getEvent()->getDate());
            $occurrenceStats[$key] ??= ['count' => 0, 'bookedByUser' => false];
            $occurrenceStats[$key]['count']++;

            if ($user && $attendee->getUser()->getId() === $user->getId()) {
                $occurrenceStats[$key]['bookedByUser'] = true;
            }
        }

        $occurrencesByDay = [];
        $period = new \DatePeriod($gridStart, new \DateInterval('P1D'), $gridEnd->modify('+1 day'));
        foreach ($period as $day) {
            $dayOccurrences = [];
            foreach ($events as $event) {
                // Drafts are only shown to team/admin as a preview of what's coming — a past draft
                // occurrence never happened for real, so it's just noise on the calendar.
                if (!$event->isPublished() && $day < $today) {
                    continue;
                }

                if ($event->isValidForDate($day)) {
                    $stats = $occurrenceStats[$this->occurrenceKey($event, $day)] ?? ['count' => 0, 'bookedByUser' => false];
                    $dayOccurrences[] = $this->buildOccurrenceView($event, $day, $user, $today, $stats);
                }
            }
            $occurrencesByDay[$day->format('Y-m-d')] = $dayOccurrences;
        }

        return $this->render('event/calendar.html.twig', [
            'monthStart'       => $monthStart,
            'gridStart'        => $gridStart,
            'gridEnd'          => $gridEnd,
            'occurrencesByDay' => $occurrencesByDay,
            'prevYear'         => $monthStart->modify('-1 month')->format('Y'),
            'prevMonth'        => $monthStart->modify('-1 month')->format('n'),
            'nextYear'         => $monthStart->modify('+1 month')->format('Y'),
            'nextMonth'        => $monthStart->modify('+1 month')->format('n'),
            'today'            => $today,
        ]);
    }

    /**
     * A stable, deep-linkable page for a single event. For a recurring event this shows the
     * one canonical event (not one page per occurrence), but keeps track of which occurrence
     * is being booked via a `date` query param — e.g. when arriving from a specific day on the
     * calendar — falling back to the next upcoming occurrence when none is given.
     */
    #[Route('/events/{id}', name: 'app_event_show', requirements: ['id' => '\d+'])]
    public function show(Request $request, Event $event, AttendeeRepository $attendeeRepository): Response
    {
        if (!$event->isPublished() && !$this->isGranted('ROLE_TEAM')) {
            throw $this->createNotFoundException('Event not found.');
        }

        $today          = new \DateTimeImmutable('today');
        $requestedDate  = $this->parseDate($request->query->get('date', ''));
        $occurrenceDate = $this->resolveOccurrenceDate($event, $requestedDate, $today);

        $storedOccurrenceDate = $event->isRecurring() ? $occurrenceDate : null;

        /** @var User|null $user */
        $user = $this->getUser();

        $stats = [
            'count'        => $event->getMaxAttendees() !== null
                ? $attendeeRepository->countActiveForOccurrence($event, $storedOccurrenceDate)
                : 0,
            'bookedByUser' => $user !== null && $attendeeRepository->findActiveBooking($event, $user, $storedOccurrenceDate) !== null,
        ];

        $view = $this->buildOccurrenceView($event, $occurrenceDate, $user, $today, $stats);
        $view['accountConflict'] = (bool) $request->query->get('accountConflict');

        // Which of this event's staffing requirements the member is qualified to volunteer for —
        // shown as a "help staff this" option alongside the normal booking form.
        $view['eligibleStaffingRequirements'] = $user
            ? array_values(array_filter(
                $event->getStaffingRequirements()->toArray(),
                static fn (EventStaffingRequirement $r) => $user->hasCertification($r->getCertification()),
            ))
            : [];

        return $this->render('event/show.html.twig', $view);
    }

    #[Route('/events/{id}/book', name: 'app_event_book', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function book(
        Request $request,
        Event $event,
        EntityManagerInterface $em,
        AttendeeRepository $attendeeRepository,
        BookingMailer $bookingMailer,
    ): Response {
        if (!$this->isCsrfTokenValid('book_event_' . $event->getId(), $request->request->get('_csrf_token'))) {
            $this->addFlash('error', 'Access denied.');
            return $this->redirectToRoute('app_events');
        }

        /** @var User $user */
        $user = $this->getUser();

        [$occurrenceDate, $redirectParams, $error] = $this->validateBookableOccurrence(
            $event,
            $request->request->get('occurrenceDate', ''),
            $this->isGranted('ROLE_TEAM'),
        );

        if ($error) {
            $this->addFlash('error', $error);
            return $this->redirectToRoute('app_event_show', $redirectParams);
        }

        $staffingRequirement = $this->resolveStaffingRequirement($event, $user, $request->request->get('staffingRequirementId', ''), $em);

        $result = $this->tryCreateBooking($event, $user, $occurrenceDate, $attendeeRepository, $em, $staffingRequirement);

        if (is_string($result)) {
            $this->addFlash('error', $result);
            return $this->redirectToRoute('app_event_show', $redirectParams);
        }

        $bookingMailer->sendBookingConfirmation($user, $event, $occurrenceDate);

        return $this->redirectToRoute('app_booking_confirmation', ['id' => $result->getId()]);
    }

    /**
     * Lets an anonymous visitor book onto an event without a separate sign-up step: they enter
     * their name, email, and a password, and we create (or log them into) their account and book
     * them in one action. If the email already has an account and the password doesn't match, we
     * don't touch that account or book anything — we send them back with a prompt to reset their
     * password instead, so we never silently take over or duplicate an existing member's account.
     */
    #[Route('/events/{id}/book-guest', name: 'app_event_book_guest', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function bookGuest(
        Request $request,
        Event $event,
        EntityManagerInterface $em,
        AttendeeRepository $attendeeRepository,
        UserRepository $userRepository,
        UserPasswordHasherInterface $passwordHasher,
        BookingMailer $bookingMailer,
        Security $security,
    ): Response {
        if (!$this->isCsrfTokenValid('book_guest_event_' . $event->getId(), $request->request->get('_csrf_token'))) {
            $this->addFlash('error', 'Access denied.');
            return $this->redirectToRoute('app_events');
        }

        if ($this->getUser()) {
            return $this->redirectToRoute('app_event_show', ['id' => $event->getId()]);
        }

        [$occurrenceDate, $redirectParams, $error] = $this->validateBookableOccurrence(
            $event,
            $request->request->get('occurrenceDate', ''),
        );

        if ($error) {
            $this->addFlash('error', $error);
            return $this->redirectToRoute('app_event_show', $redirectParams);
        }

        $firstName = trim($request->request->get('firstName', ''));
        $lastName  = trim($request->request->get('lastName', ''));
        $email     = strtolower(trim($request->request->get('email', '')));
        $password  = $request->request->get('password', '');
        $optIn     = $request->request->has('optIn');

        if ($firstName === '' || $lastName === '' || !$email || !filter_var($email, FILTER_VALIDATE_EMAIL) || $password === '') {
            $this->addFlash('error', 'Please fill in your name, email, and a password.');
            return $this->redirectToRoute('app_event_show', $redirectParams);
        }

        if (strlen($password) < 8) {
            $this->addFlash('error', 'Your password must be at least 8 characters.');
            return $this->redirectToRoute('app_event_show', $redirectParams);
        }

        $existingUser = $userRepository->findOneBy(['email' => $email]);

        if ($existingUser) {
            if (!$passwordHasher->isPasswordValid($existingUser, $password)) {
                return $this->redirectToRoute('app_event_show', array_merge($redirectParams, ['accountConflict' => 1]));
            }

            $user = $existingUser;
            if ($optIn && !$user->isOptIn()) {
                $user->setOptIn(true);
            }
        } else {
            $user = new User();
            $user->setEmail($email);
            $user->setFirstName($firstName);
            $user->setLastName($lastName);
            $user->setOptIn($optIn);
            $user->setPassword($passwordHasher->hashPassword($user, $password));

            $em->persist($user);

            $note = new Note();
            $note->setUser($user);
            $note->setContent('Contact added via event booking: "' . $event->getTitle() . '".');
            $em->persist($note);
        }

        $em->flush();

        $security->login($user);

        $result = $this->tryCreateBooking($event, $user, $occurrenceDate, $attendeeRepository, $em);

        if (is_string($result)) {
            $this->addFlash('error', $result);
            return $this->redirectToRoute('app_event_show', $redirectParams);
        }

        $bookingMailer->sendBookingConfirmation($user, $event, $occurrenceDate);

        return $this->redirectToRoute('app_booking_confirmation', ['id' => $result->getId()]);
    }

    #[Route('/bookings/{id}/confirmation', name: 'app_booking_confirmation', requirements: ['id' => '\d+'])]
    #[IsGranted('ROLE_USER')]
    public function bookingConfirmation(Attendee $attendee): Response
    {
        if ($attendee->getUser() !== $this->getUser()) {
            throw $this->createAccessDeniedException();
        }

        return $this->render('event/booking_confirmation.html.twig', [
            'attendee' => $attendee,
        ]);
    }

    #[Route('/events/{id}/cancel', name: 'app_event_cancel', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function cancel(
        Request $request,
        Event $event,
        EntityManagerInterface $em,
        AttendeeRepository $attendeeRepository,
    ): Response {
        if (!$this->isCsrfTokenValid('cancel_event_' . $event->getId(), $request->request->get('_csrf_token'))) {
            $this->addFlash('error', 'Access denied.');
            return $this->redirectToRoute('app_home');
        }

        /** @var User $user */
        $user = $this->getUser();

        $occurrenceDate       = $this->parseDate($request->request->get('occurrenceDate', ''));
        $storedOccurrenceDate = $occurrenceDate !== null && $event->isRecurring() ? $occurrenceDate : null;

        $attendee = $occurrenceDate !== null
            ? $attendeeRepository->findActiveBooking($event, $user, $storedOccurrenceDate)
            : null;

        if (!$attendee) {
            $this->addFlash('error', 'We could not find that booking.');
            return $this->redirect($this->generateUrl('app_account') . '#bookings');
        }

        $attendee->setStatus(Attendee::STATUS_CANCELLED);
        $em->flush();

        $this->addFlash('success', 'Your booking has been cancelled.');
        return $this->redirect($this->generateUrl('app_account') . '#bookings');
    }

    /**
     * Parses and validates a submitted occurrence date against the event, returning the params
     * needed to redirect back to the event page either way.
     *
     * @return array{0: ?\DateTimeImmutable, 1: array, 2: ?string}
     */
    private function validateBookableOccurrence(Event $event, string $rawDate, bool $allowDraft = false): array
    {
        $occurrenceDate = $this->parseDate($rawDate);
        $redirectParams = ['id' => $event->getId()];
        if ($occurrenceDate) {
            $redirectParams['date'] = $occurrenceDate->format('Y-m-d');
        }

        if ((!$event->isPublished() && !$allowDraft) || $occurrenceDate === null || !$event->isValidForDate($occurrenceDate)) {
            return [null, $redirectParams, 'That event is no longer available.'];
        }

        if ($occurrenceDate < new \DateTimeImmutable('today')) {
            return [null, $redirectParams, 'That date has already passed.'];
        }

        return [$occurrenceDate, $redirectParams, null];
    }

    /** The submitted staffing requirement, if any — only honoured when it belongs to this event and the member holds its certification. */
    private function resolveStaffingRequirement(Event $event, User $user, string $rawId, EntityManagerInterface $em): ?EventStaffingRequirement
    {
        if ($rawId === '') {
            return null;
        }

        $requirement = $em->getRepository(EventStaffingRequirement::class)->find((int) $rawId);

        if (!$requirement || $requirement->getEvent() !== $event || !$user->hasCertification($requirement->getCertification())) {
            return null;
        }

        return $requirement;
    }

    /** Validates and creates the booking, returning the new Attendee or an error message. */
    private function tryCreateBooking(
        Event $event,
        User $user,
        \DateTimeImmutable $occurrenceDate,
        AttendeeRepository $attendeeRepository,
        EntityManagerInterface $em,
        ?EventStaffingRequirement $staffingRequirement = null,
    ): Attendee|string {
        if (!$event->allowsUser($user)) {
            return 'You do not hold the certification required to book this event.';
        }

        $storedOccurrenceDate = $event->isRecurring() ? $occurrenceDate : null;

        if ($attendeeRepository->findActiveBooking($event, $user, $storedOccurrenceDate)) {
            return 'You are already booked onto this event.';
        }

        if ($event->getMaxAttendees() !== null
            && $attendeeRepository->countActiveForOccurrence($event, $storedOccurrenceDate) >= $event->getMaxAttendees()
        ) {
            return 'Sorry, this event is fully booked.';
        }

        $attendee = new Attendee();
        $attendee->setEvent($event);
        $attendee->setUser($user);
        $attendee->setOccurrenceDate($storedOccurrenceDate);
        $attendee->setStatus(Attendee::STATUS_CONFIRMED);

        if ($staffingRequirement) {
            $attendee->setStaffingRequirement($staffingRequirement);
            $attendee->setStaffingStatus(Attendee::STAFFING_PENDING);
        }

        $em->persist($attendee);
        $em->flush();

        return $attendee;
    }

    /** @param array{count: int, bookedByUser: bool} $stats */
    private function buildOccurrenceView(Event $event, \DateTimeImmutable $date, ?User $user, \DateTimeImmutable $today, array $stats): array
    {
        $isPast = $date < $today;

        $isFull    = false;
        $spotsLeft = null;

        if ($event->getMaxAttendees() !== null) {
            $spotsLeft = max(0, $event->getMaxAttendees() - $stats['count']);
            $isFull    = $spotsLeft <= 0;
        }

        $isBooked     = $user ? $stats['bookedByUser'] : false;
        $isRestricted = $user ? !$event->allowsUser($user) : false;

        // A draft is only ever reachable here as a published event, or as a team/admin preview
        // (show()/index() already gate that) — so team/admin can book onto it like any other
        // event, e.g. to put themselves on duty and build out the rota before publishing.
        $canBookUnpublished = !$event->isPublished() && $this->isGranted('ROLE_TEAM');

        return [
            'event'        => $event,
            'date'         => $date,
            'isPast'       => $isPast,
            'isFull'       => $isFull,
            'spotsLeft'    => $spotsLeft,
            'isBooked'     => $isBooked,
            'isRestricted' => $isRestricted,
            'isDraft'      => !$event->isPublished(),
            'canBook'      => $user !== null && ($event->isPublished() || $canBookUnpublished) && !$isPast && !$isBooked && !$isFull && !$isRestricted,
        ];
    }

    /**
     * Which occurrence to show/book on the event page: the requested date if it's a real
     * occurrence of this event, otherwise the next upcoming one, falling back to the most
     * recent past occurrence if the event (or its recurrence window) has already ended.
     */
    private function resolveOccurrenceDate(Event $event, ?\DateTimeImmutable $requested, \DateTimeImmutable $today): \DateTimeImmutable
    {
        if ($requested !== null && $event->isValidForDate($requested)) {
            return $requested;
        }

        if (!$event->isRecurring()) {
            return $event->getDate();
        }

        $searchFrom = max($event->getDate(), $today);

        for ($i = 0; $i < 7; $i++) {
            $candidate = $searchFrom->modify("+{$i} days");
            if ($event->getRecurUntil() && $candidate > $event->getRecurUntil()) {
                break;
            }
            if ($event->isValidForDate($candidate)) {
                return $candidate;
            }
        }

        if ($event->getRecurUntil()) {
            for ($i = 0; $i < 7; $i++) {
                $candidate = $event->getRecurUntil()->modify("-{$i} days");
                if ($candidate < $event->getDate()) {
                    break;
                }
                if ($event->isValidForDate($candidate)) {
                    return $candidate;
                }
            }
        }

        return $event->getDate();
    }

    private function occurrenceKey(Event $event, \DateTimeImmutable $date): string
    {
        return $event->getId() . '|' . ($event->isRecurring() ? $date->format('Y-m-d') : 'single');
    }

    private function parseDate(string $raw): ?\DateTimeImmutable
    {
        try {
            return new \DateTimeImmutable($raw);
        } catch (\Exception) {
            return null;
        }
    }
}
