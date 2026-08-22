<?php

namespace App\Controller;

use App\Entity\Attendee;
use App\Entity\Event;
use App\Entity\User;
use App\Repository\AttendeeRepository;
use App\Repository\EventRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

class EventController extends AbstractController
{
    public function __construct(
        #[Autowire('%env(MAILER_FROM)%')]      private readonly string $mailerFrom,
        #[Autowire('%env(MAILER_FROM_NAME)%')] private readonly string $mailerFromName,
    ) {}

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

        $events = $eventRepository->findPublishedOverlapping($gridStart, $gridEnd);

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
        if (!$event->isPublished()) {
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

        return $this->render('event/show.html.twig', $this->buildOccurrenceView($event, $occurrenceDate, $user, $today, $stats));
    }

    #[Route('/events/{id}/book', name: 'app_event_book', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function book(
        Request $request,
        Event $event,
        EntityManagerInterface $em,
        AttendeeRepository $attendeeRepository,
        MailerInterface $mailer,
    ): Response {
        if (!$this->isCsrfTokenValid('book_event_' . $event->getId(), $request->request->get('_csrf_token'))) {
            $this->addFlash('error', 'Access denied.');
            return $this->redirectToRoute('app_events');
        }

        /** @var User $user */
        $user = $this->getUser();

        $occurrenceDate = $this->parseDate($request->request->get('occurrenceDate', ''));
        $redirectParams = ['id' => $event->getId()];
        if ($occurrenceDate) {
            $redirectParams['date'] = $occurrenceDate->format('Y-m-d');
        }

        if (!$event->isPublished() || $occurrenceDate === null || !$event->isValidForDate($occurrenceDate)) {
            $this->addFlash('error', 'That event is no longer available.');
            return $this->redirectToRoute('app_event_show', $redirectParams);
        }

        if ($occurrenceDate < new \DateTimeImmutable('today')) {
            $this->addFlash('error', 'That date has already passed.');
            return $this->redirectToRoute('app_event_show', $redirectParams);
        }

        if (!$event->allowsUser($user)) {
            $this->addFlash('error', 'You do not hold the certification required to book this event.');
            return $this->redirectToRoute('app_event_show', $redirectParams);
        }

        $storedOccurrenceDate = $event->isRecurring() ? $occurrenceDate : null;

        if ($attendeeRepository->findActiveBooking($event, $user, $storedOccurrenceDate)) {
            $this->addFlash('error', 'You are already booked onto this event.');
            return $this->redirectToRoute('app_event_show', $redirectParams);
        }

        if ($event->getMaxAttendees() !== null
            && $attendeeRepository->countActiveForOccurrence($event, $storedOccurrenceDate) >= $event->getMaxAttendees()
        ) {
            $this->addFlash('error', 'Sorry, this event is fully booked.');
            return $this->redirectToRoute('app_event_show', $redirectParams);
        }

        $attendee = new Attendee();
        $attendee->setEvent($event);
        $attendee->setUser($user);
        $attendee->setOccurrenceDate($storedOccurrenceDate);
        $attendee->setStatus(Attendee::STATUS_CONFIRMED);

        $em->persist($attendee);
        $em->flush();

        $this->sendBookingConfirmation($mailer, $user, $event, $occurrenceDate);

        return $this->redirectToRoute('app_booking_confirmation', ['id' => $attendee->getId()]);
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

        return [
            'event'        => $event,
            'date'         => $date,
            'isPast'       => $isPast,
            'isFull'       => $isFull,
            'spotsLeft'    => $spotsLeft,
            'isBooked'     => $isBooked,
            'isRestricted' => $isRestricted,
            'canBook'      => $user !== null && !$isPast && !$isBooked && !$isFull && !$isRestricted,
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

    private function sendBookingConfirmation(MailerInterface $mailer, User $user, Event $event, \DateTimeImmutable $occurrenceDate): void
    {
        $email = (new TemplatedEmail())
            ->from(new Address($this->mailerFrom, $this->mailerFromName))
            ->to($user->getEmail())
            ->subject('Booking confirmed: ' . $event->getTitle() . ' — Y Wal')
            ->htmlTemplate('email/booking_confirmation.html.twig')
            ->textTemplate('email/booking_confirmation.txt.twig')
            ->context([
                'user'           => $user,
                'event'          => $event,
                'occurrenceDate' => $occurrenceDate,
            ]);

        $mailer->send($email);
    }
}
