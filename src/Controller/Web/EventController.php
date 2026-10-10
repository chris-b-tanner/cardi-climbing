<?php

namespace App\Controller\Web;

use App\Entity\Attendee;
use App\Entity\Event;
use App\Entity\EventStaffingRequirement;
use App\Entity\User;
use App\Repository\AttendeeRepository;
use App\Repository\EventRepository;
use App\Service\Mailer\BookingMailer;
use App\Service\BookingService;
use App\Service\EventOccurrenceService;
use App\Service\UserService;
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
    public function __construct(
        private readonly EventOccurrenceService $occurrences,
    ) {}

    #[Route('/events', name: 'app_events')]
    public function index(Request $request, EventRepository $eventRepository): Response
    {
        /** @var User|null $user */
        $user  = $this->getUser();
        $today = new \DateTimeImmutable('today');

        // Searching by title filters which events populate the week grid rather than switching to
        // a different layout — a plain GET form (rather than AJAX) so it's deep-linkable and works
        // without JS, consistent with the week/date navigation already using plain links.
        $searchQuery   = trim($request->query->get('q', ''));
        $requestedDate = EventOccurrenceService::parseDate($request->query->get('date', ''));

        if ($requestedDate !== null) {
            $anchor = $requestedDate;
        } elseif ($searchQuery !== '') {
            // No explicit date given alongside a search — jump straight to the week of the
            // earliest upcoming match instead of showing an empty grid for the current week.
            $anchor = $this->findAnchorDateForSearch($searchQuery, $today, $eventRepository);
        } else {
            $anchor = $today;
        }

        $weekStart = $anchor->modify('monday this week');
        $weekEnd   = $weekStart->modify('+6 days');

        $events = $eventRepository->findPublishedOverlapping($weekStart, $weekEnd, $this->isGranted('ROLE_TEAM'), $searchQuery);

        return $this->render('event/calendar.html.twig', [
            'weekStart'   => $weekStart,
            'weekEnd'     => $weekEnd,
            'days'        => $this->occurrences->buildWeek($events, $weekStart, $weekEnd, $user),
            'prevWeek'    => $weekStart->modify('-7 days')->format('Y-m-d'),
            'nextWeek'    => $weekStart->modify('+7 days')->format('Y-m-d'),
            'today'       => $today,
            'searchQuery' => $searchQuery,
        ]);
    }

    /** The earliest upcoming occurrence date of the first title match, or today if nothing matches — so a search with no explicit date jumps straight to a week that actually shows something. */
    private function findAnchorDateForSearch(string $query, \DateTimeImmutable $today, EventRepository $eventRepository): \DateTimeImmutable
    {
        $matches = $eventRepository->searchUpcoming($query, $today, $this->isGranted('ROLE_TEAM'));
        if ($matches === []) {
            return $today;
        }

        return $this->occurrences->resolveOccurrenceDate($matches[0], null, $today);
    }

    /**
     * A stable, deep-linkable page for a single event. For a recurring event this shows the
     * one canonical event (not one page per occurrence), but keeps track of which occurrence
     * is being booked via a `date` query param — e.g. when arriving from a specific day on the
     * calendar — falling back to the next upcoming occurrence when none is given.
     */
    #[Route('/events/{id}', name: 'app_event_show', requirements: ['id' => '\d+'])]
    public function show(Request $request, Event $event): Response
    {
        /** @var User|null $user */
        $user = $this->getUser();

        $view = $this->buildPageView($request, $event, checkBookedByUser: true, withTicketContext: false);
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

    /**
     * The preview modal shown from the calendar before landing on the full event page — everything
     * the full page shows except the booking form itself, which the modal replaces with a single
     * "book now" / "log in to book" button (not yet wired up to anything — that's the cart, to come).
     */
    #[Route('/events/{id}/preview', name: 'app_event_preview', requirements: ['id' => '\d+'])]
    public function preview(Request $request, Event $event): Response
    {
        return $this->render('event/_preview.html.twig', $this->buildPageView($request, $event, checkBookedByUser: false, withTicketContext: true));
    }

    /**
     * A shareable public landing page for a single event — the same content as the calendar's
     * preview modal (including the ticket-purchase flow, unlike the simpler show() page), so a
     * link to this page works standalone for someone arriving from outside the calendar (a shared
     * link, a poster QR code, etc). For a recurring event, {date} picks which occurrence is shown,
     * same as preview()/show() — falling back to the next upcoming occurrence when omitted.
     */
    #[Route('/events/{id}/details', name: 'app_event_landing', requirements: ['id' => '\d+'])]
    public function landing(Request $request, Event $event): Response
    {
        return $this->render('event/landing.html.twig', $this->buildPageView($request, $event, checkBookedByUser: true, withTicketContext: true));
    }

    /** The shared body of show()/preview()/landing() — drafts are only visible to team/admin, and `?date=` picks the occurrence. */
    private function buildPageView(Request $request, Event $event, bool $checkBookedByUser, bool $withTicketContext): array
    {
        if (!$event->isPublished() && !$this->isGranted('ROLE_TEAM')) {
            throw $this->createNotFoundException('Event not found.');
        }

        /** @var User|null $user */
        $user = $this->getUser();

        return $this->occurrences->buildPageView(
            $event,
            EventOccurrenceService::parseDate($request->query->get('date', '')),
            $user,
            $checkBookedByUser,
            $withTicketContext,
        );
    }

    #[Route('/events/{id}/book', name: 'app_event_book', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function book(
        Request $request,
        Event $event,
        EntityManagerInterface $em,
        BookingService $bookingService,
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

        $result = $bookingService->createBooking($event, $user, $occurrenceDate, staffingRequirement: $staffingRequirement);

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
        BookingService $bookingService,
        UserService $userService,
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

        if (strlen($password) < UserService::MIN_PASSWORD_LENGTH) {
            $this->addFlash('error', 'Your password must be at least ' . UserService::MIN_PASSWORD_LENGTH . ' characters.');
            return $this->redirectToRoute('app_event_show', $redirectParams);
        }

        $existingUser = $userService->findExistingByEmail($email);

        if ($existingUser) {
            if (!$passwordHasher->isPasswordValid($existingUser, $password)) {
                return $this->redirectToRoute('app_event_show', array_merge($redirectParams, ['accountConflict' => 1]));
            }

            $user = $existingUser;
            if ($optIn && !$user->isOptIn()) {
                $user->setOptIn(true);
                $em->flush();
            }
        } else {
            // Not routed through UserService::createContact() — that's for "quick contact, no
            // real login yet" cases, whereas this is genuine self-registration with a real,
            // member-chosen password (logged into immediately below).
            $user = new User();
            $user->setEmail($email);
            $user->setFirstName($firstName);
            $user->setLastName($lastName);
            $user->setOptIn($optIn);

            $userService->registerMember($user, $password, 'Contact added via event booking: "' . $event->getTitle() . '".');
        }

        $security->login($user);

        $result = $bookingService->createBooking($event, $user, $occurrenceDate);

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
        AttendeeRepository $attendeeRepository,
        BookingService $bookingService,
    ): Response {
        if (!$this->isCsrfTokenValid('cancel_event_' . $event->getId(), $request->request->get('_csrf_token'))) {
            $this->addFlash('error', 'Access denied.');
            return $this->redirectToRoute('app_home');
        }

        /** @var User $user */
        $user = $this->getUser();

        $occurrenceDate       = EventOccurrenceService::parseDate($request->request->get('occurrenceDate', ''));
        $storedOccurrenceDate = $occurrenceDate !== null && $event->isRecurring() ? $occurrenceDate : null;

        $attendee = $occurrenceDate !== null
            ? $attendeeRepository->findActiveBooking($event, $user, $storedOccurrenceDate)
            : null;

        if (!$attendee) {
            $this->addFlash('error', 'We could not find that booking.');
            return $this->redirect($this->generateUrl('app_account') . '#bookings');
        }

        $bookingService->cancelBooking($attendee, $user);

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
        $occurrenceDate = EventOccurrenceService::parseDate($rawDate);
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
}
