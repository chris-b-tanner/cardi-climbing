<?php

namespace App\Controller;

use App\Entity\Attendee;
use App\Entity\Event;
use App\Entity\EventStaffingRequirement;
use App\Entity\Product;
use App\Entity\User;
use App\Repository\AttendeeRepository;
use App\Repository\EventRepository;
use App\Repository\ProductRepository;
use App\Service\BookingMailer;
use App\Service\BookingService;
use App\Service\CartService;
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
    #[Route('/events', name: 'app_events')]
    public function index(Request $request, EventRepository $eventRepository, AttendeeRepository $attendeeRepository): Response
    {
        /** @var User|null $user */
        $user  = $this->getUser();
        $today = new \DateTimeImmutable('today');

        // Searching by title filters which events populate the week grid rather than switching to
        // a different layout — a plain GET form (rather than AJAX) so it's deep-linkable and works
        // without JS, consistent with the week/date navigation already using plain links.
        $searchQuery   = trim($request->query->get('q', ''));
        $requestedDate = $this->parseDate($request->query->get('date', ''));

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

        // Fetch every booking for these events across the whole week in one query, then
        // derive per-occurrence counts/booked-state from it in memory — avoids running a
        // count + booking-lookup query for every single occurrence shown on the calendar.
        $eventIds        = array_map(static fn (Event $e) => $e->getId(), $events);
        $activeAttendees = $attendeeRepository->findActiveForEventsInRange($eventIds, $weekStart, $weekEnd);

        $occurrenceStats = [];
        foreach ($activeAttendees as $attendee) {
            $key = $this->occurrenceKey($attendee->getEvent(), $attendee->getOccurrenceDate() ?? $attendee->getEvent()->getDate());
            $occurrenceStats[$key] ??= ['count' => 0, 'bookedByUser' => false];
            $occurrenceStats[$key]['count']++;

            if ($user && $attendee->getUser()->getId() === $user->getId()) {
                $occurrenceStats[$key]['bookedByUser'] = true;
            }
        }

        $days = [];
        $period = new \DatePeriod($weekStart, new \DateInterval('P1D'), $weekEnd->modify('+1 day'));
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
                    $dayOccurrences[] = $this->buildOccurrenceView($event, $day, $user, $stats);
                }
            }

            usort($dayOccurrences, static fn(array $a, array $b) => $a['event']->getTimeFrom() <=> $b['event']->getTimeFrom());

            $days[] = ['date' => $day, 'events' => $dayOccurrences];
        }

        return $this->render('event/calendar.html.twig', [
            'weekStart'   => $weekStart,
            'weekEnd'     => $weekEnd,
            'days'        => $days,
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

        return $this->resolveOccurrenceDate($matches[0], null, $today);
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

        $view = $this->buildOccurrenceView($event, $occurrenceDate, $user, $stats);
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
    public function preview(Request $request, Event $event, AttendeeRepository $attendeeRepository, ProductRepository $productRepository, CartService $cartService): Response
    {
        if (!$event->isPublished() && !$this->isGranted('ROLE_TEAM')) {
            throw $this->createNotFoundException('Event not found.');
        }

        $today          = new \DateTimeImmutable('today');
        $requestedDate  = $this->parseDate($request->query->get('date', ''));
        $occurrenceDate = $this->resolveOccurrenceDate($event, $requestedDate, $today);

        $storedOccurrenceDate = $event->isRecurring() ? $occurrenceDate : null;

        $stats = [
            'count'        => $event->getMaxAttendees() !== null
                ? $attendeeRepository->countActiveForOccurrence($event, $storedOccurrenceDate)
                : 0,
            'bookedByUser' => false,
        ];

        /** @var User|null $user */
        $user = $this->getUser();

        $view = $this->buildOccurrenceView($event, $occurrenceDate, $user, $stats);
        $view['eventTicketProducts'] = $eventTicketProducts = $productRepository->findActiveEventTickets($event);

        $ticketAccess = [];
        foreach ($eventTicketProducts as $ticketProduct) {
            $ticketAccess[$ticketProduct->getId()] = $this->userQualifiesForTicket($user, $ticketProduct);
        }
        $view['ticketAccess'] = $ticketAccess;

        $cartTicketCount = 0;
        if ($user !== null) {
            foreach ($cartService->getLines($user) as $line) {
                if (in_array($line['product'], $eventTicketProducts, true) && $line['occurrenceDate'] == $storedOccurrenceDate) {
                    $cartTicketCount++;
                }
            }
        }
        $view['cartTicketCount'] = $cartTicketCount;
        $view['existingBookingCount'] = $user !== null ? $attendeeRepository->countActiveForUserOccurrence($event, $user, $storedOccurrenceDate) : 0;

        return $this->render('event/_preview.html.twig', $view);
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

        $bookingMailer->sendBookingConfirmation($user, $event, $occurrenceDate, $result->getPin());

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

        if (strlen($password) < 8) {
            $this->addFlash('error', 'Your password must be at least 8 characters.');
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
            $user->setPassword($passwordHasher->hashPassword($user, $password));

            $em->persist($user);
            $em->flush(); // assigns $user's id — needed before a Note can reference it via noteableId

            $userService->addNote($user, 'Contact added via event booking: "' . $event->getTitle() . '".');
        }

        $em->flush();

        $security->login($user);

        $result = $bookingService->createBooking($event, $user, $occurrenceDate);

        if (is_string($result)) {
            $this->addFlash('error', $result);
            return $this->redirectToRoute('app_event_show', $redirectParams);
        }

        $bookingMailer->sendBookingConfirmation($user, $event, $occurrenceDate, $result->getPin());

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

        $occurrenceDate       = $this->parseDate($request->request->get('occurrenceDate', ''));
        $storedOccurrenceDate = $occurrenceDate !== null && $event->isRecurring() ? $occurrenceDate : null;

        $attendee = $occurrenceDate !== null
            ? $attendeeRepository->findActiveBooking($event, $user, $storedOccurrenceDate)
            : null;

        if (!$attendee) {
            $this->addFlash('error', 'We could not find that booking.');
            return $this->redirect($this->generateUrl('app_account') . '#bookings');
        }

        $bookingService->cancelBooking($attendee);

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

    /**
     * Whether $user can select this ticket's price — always true for an open ticket, otherwise
     * only if $user (or one of their dependents, who they can also book the ticket for) currently
     * holds the membership type it's restricted to.
     */
    private function userQualifiesForTicket(?User $user, Product $ticketProduct): bool
    {
        $membershipType = $ticketProduct->getEventTicketProduct()?->getMembershipType();

        if ($membershipType === null) {
            return true;
        }

        if ($user === null) {
            return false;
        }

        if ($user->hasActiveMembershipType($membershipType)) {
            return true;
        }

        foreach ($user->getDependents() as $dependent) {
            if ($dependent->hasActiveMembershipType($membershipType)) {
                return true;
            }
        }

        return false;
    }

    /** @param array{count: int, bookedByUser: bool} $stats */
    private function buildOccurrenceView(Event $event, \DateTimeImmutable $date, ?User $user, array $stats): array
    {
        // Past means fully ended, not just "started" — an occurrence that's under way right now
        // (or hasn't reached its end time yet today) still shows the booking/ticket form.
        // combineDateAndTime() reads timeTo as UK wall-clock and converts to UTC (not literal
        // UTC) so this stays correct across a DST change, same as the door-access API.
        $isPast = $event->combineDateAndTime($date, $event->getTimeTo()) < new \DateTimeImmutable();

        $isFull    = false;
        $spotsLeft = null;

        if ($event->getMaxAttendees() !== null) {
            $spotsLeft = max(0, $event->getMaxAttendees() - $stats['count']);
            $isFull    = $spotsLeft <= 0;
        }

        $isBooked     = $user ? $stats['bookedByUser'] : false;
        $isRestricted = $user ? !$event->allowsUser($user) : false;

        // Whether membership/credit already covers a free seat — checked against only the access
        // methods the event actually accepts, since either one on its own is now a valid, complete
        // route (see Event::acceptsMembership()/acceptsCredit()).
        $coveredByMembership = false;
        $coveredByCredit     = false;
        if ($user !== null) {
            if ($event->acceptsMembership()) {
                $membership          = $user->getEffectiveMembership();
                $coveredByMembership = $membership !== null && $membership->isCurrentlyActive();
            }
            if (!$coveredByMembership && $event->acceptsCredit()) {
                $coveredByCredit = $user->getCreditBalance() > 0;
            }
        }

        // Whether this event is a membership/credit-gated event at all — a property of the event
        // itself, not of whether this particular user already satisfies it. Drives the direct-book
        // button's "Check in" (something is being verified/spent) vs "Book now" wording even when
        // the user is covered for free by their membership.
        $needsMembershipOrCredit = $event->acceptsCredit() || $event->acceptsMembership();

        // Open booking (no access method selected) is always free; otherwise membership/credit
        // cover is the free route. If neither applies but a ticket is also accepted, the ticket
        // purchase route takes over instead of a hard block.
        $canBookFree                 = !$event->hasAccessRestriction() || $coveredByMembership || $coveredByCredit;
        $needsTicket                 = !$canBookFree && $event->acceptsTicket();
        $blockedByMembershipOrCredit = !$canBookFree && !$needsTicket;

        // A certification-restricted event needs a way to reach the booker in an emergency —
        // checked here regardless of access method, same as before.
        $blockedByMissingEmergencyContact = !$event->getRestrictions()->isEmpty() && $user !== null && !$user->hasCompleteEmergencyContact();

        // Only set when an active membership is what covers this booking — lets the template tell
        // "no payment needed" (membership) apart from "a credit will be spent" (no membership).
        $activeMembership = $coveredByMembership ? $user->getEffectiveMembership() : null;

        // A draft is only ever reachable here as a published event, or as a team/admin preview
        // (show()/index() already gate that) — so team/admin can book onto it like any other
        // event, e.g. to put themselves on duty and build out the rota before publishing.
        $canBookUnpublished = !$event->isPublished() && $this->isGranted('ROLE_TEAM');

        return [
            'event'                            => $event,
            'date'                             => $date,
            'isPast'                           => $isPast,
            'isFull'                           => $isFull,
            'spotsLeft'                        => $spotsLeft,
            'isBooked'                         => $isBooked,
            'isRestricted'                     => $isRestricted,
            'needsMembershipOrCredit'          => $needsMembershipOrCredit,
            'needsTicket'                      => $needsTicket,
            'blockedByMembershipOrCredit'      => $blockedByMembershipOrCredit,
            'blockedByMissingEmergencyContact' => $blockedByMissingEmergencyContact,
            'activeMembershipTypeName'         => $activeMembership?->getMembershipType()->getName(),
            'isDraft'                          => !$event->isPublished(),
            'canBook'                          => $user !== null && ($event->isPublished() || $canBookUnpublished) && !$isPast && !$isBooked && !$isFull && !$isRestricted && !$needsTicket && !$blockedByMembershipOrCredit && !$blockedByMissingEmergencyContact,
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
        // Guard against the empty string specifically: DateTimeImmutable's constructor treats it
        // like "now" rather than throwing, so without this every caller's "no date given" case
        // would silently resolve to today instead of null.
        if ($raw === '') {
            return null;
        }

        try {
            return new \DateTimeImmutable($raw);
        } catch (\Exception) {
            return null;
        }
    }
}
