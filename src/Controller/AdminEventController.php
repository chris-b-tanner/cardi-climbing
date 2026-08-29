<?php

namespace App\Controller;

use App\Entity\Attendee;
use App\Entity\Event;
use App\Entity\EventStaffingRequirement;
use App\Entity\User;
use App\Repository\AttendeeRepository;
use App\Repository\CertificationRepository;
use App\Repository\EventRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/admin/events')]
#[IsGranted('ROLE_TEAM')]
class AdminEventController extends AbstractController
{
    #[Route('', name: 'app_admin_events')]
    public function index(EventRepository $eventRepository): Response
    {
        return $this->render('admin/events/index.html.twig', [
            'events' => $eventRepository->findAllOrdered(),
        ]);
    }

    #[Route('/new', name: 'app_admin_event_new', methods: ['GET', 'POST'])]
    public function new(
        Request $request,
        EntityManagerInterface $em,
        CertificationRepository $certificationRepository,
    ): Response {
        $allCertifications = $certificationRepository->findBy([], ['name' => 'ASC']);
        $error = null;

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('admin_event_new', $request->request->get('_csrf_token'))) {
                $this->addFlash('error', 'Access denied.');
                return $this->redirectToRoute('app_home');
            }

            $event = new Event();

            /** @var User $admin */
            $admin = $this->getUser();
            $event->setAuthor($admin);

            $error = $this->applyRequestToEvent($request, $event, $allCertifications);

            if (!$error) {
                $em->persist($event);
                $this->applyStaffingRequirements($request, $event, $allCertifications, $em);
                $em->flush();

                $this->addFlash('success', 'Event created.');
                return $this->redirectToRoute('app_admin_events');
            }
        }

        return $this->render('admin/events/new.html.twig', [
            'error'         => $error,
            'certifications' => $allCertifications,
        ]);
    }

    /**
     * The event info screen — static details plus the attendee list. Available to team and
     * admins alike. For a recurring event, ?date= picks which occurrence's attendees are shown.
     */
    #[Route('/{id}', name: 'app_admin_event_show', requirements: ['id' => '\d+'])]
    public function show(Request $request, Event $event, AttendeeRepository $attendeeRepository): Response
    {
        $occurrenceDate = null;
        $prevDate       = null;
        $nextDate       = null;

        if ($event->isRecurring()) {
            $requestedDate = null;
            $requestedRaw  = $request->query->get('date', '');
            if ($requestedRaw !== '') {
                try {
                    $requestedDate = new \DateTimeImmutable($requestedRaw);
                } catch (\Exception) {
                    $requestedDate = null;
                }
            }

            $occurrenceDate = $this->resolveOccurrenceDate($event, $requestedDate);
            $prevDate       = $this->adjacentOccurrence($event, $occurrenceDate, -1);
            $nextDate       = $this->adjacentOccurrence($event, $occurrenceDate, 1);

            $attendees = $attendeeRepository->findForEventOccurrence($event, $occurrenceDate);
        } else {
            $attendees = $attendeeRepository->findForEvent($event);
        }

        $storedOccurrenceDate = $event->isRecurring() ? $occurrenceDate : null;
        $staffing             = $attendeeRepository->findStaffingForOccurrence($event, $storedOccurrenceDate);

        return $this->render('admin/events/show.html.twig', [
            'event'          => $event,
            'attendees'      => $attendees,
            'occurrenceDate' => $occurrenceDate,
            'prevDate'       => $prevDate,
            'nextDate'       => $nextDate,
            'staffing'       => $staffing,
        ]);
    }

    /** Admin picks an already-subscribed, cert-holding attendee to put on duty for a requirement — approved immediately. */
    #[Route('/{id}/staffing/assign', name: 'app_admin_event_staffing_assign', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function assignStaffing(
        Request $request,
        Event $event,
        EntityManagerInterface $em,
        AttendeeRepository $attendeeRepository,
    ): Response {
        if (!$this->isCsrfTokenValid('admin_event_staffing_' . $event->getId(), $request->request->get('_csrf_token'))) {
            $this->addFlash('error', 'Access denied.');
            return $this->redirectToRoute('app_home');
        }

        $showParams = ['id' => $event->getId()];
        $occurrenceDateRaw = trim($request->request->get('occurrenceDate', ''));
        if ($occurrenceDateRaw !== '') {
            $showParams['date'] = $occurrenceDateRaw;
        }

        $requirement = $em->getRepository(EventStaffingRequirement::class)->find((int) $request->request->get('requirementId'));
        $attendee    = $attendeeRepository->find((int) $request->request->get('attendeeId'));

        if (!$requirement || $requirement->getEvent() !== $event || !$attendee || $attendee->getEvent() !== $event) {
            $this->addFlash('error', 'Could not find that member on this occurrence.');
            return $this->redirectToRoute('app_admin_event_show', $showParams);
        }

        if (!$attendee->getUser()->hasCertification($requirement->getCertification())) {
            $this->addFlash('error', 'That member does not hold the required certification.');
            return $this->redirectToRoute('app_admin_event_show', $showParams);
        }

        $attendee->setStaffingRequirement($requirement);
        $attendee->setStaffingStatus(Attendee::STAFFING_APPROVED);
        $em->flush();

        $this->addFlash('success', 'Member put on duty.');
        return $this->redirectToRoute('app_admin_event_show', $showParams);
    }

    /**
     * A month-grid calendar of every occurrence with a staffing requirement, flagging those that
     * are short of their minimum on-duty coverage. Reuses the same in-memory occurrence-expansion
     * and batch-attendee-loading approach as the public events calendar.
     */
    #[Route('/rota/calendar', name: 'app_admin_rota')]
    public function rota(Request $request, EventRepository $eventRepository, AttendeeRepository $attendeeRepository): Response
    {
        $year  = (int) $request->query->get('year', (int) date('Y'));
        $month = (int) $request->query->get('month', (int) date('n'));

        $year  += intdiv($month - 1, 12);
        $month = (($month - 1) % 12 + 12) % 12 + 1;

        $monthStart = new \DateTimeImmutable(sprintf('%04d-%02d-01', $year, $month));
        $monthEnd   = $monthStart->modify('last day of this month');

        $gridStart = $monthStart->modify('monday this week');
        $gridEnd   = $monthEnd->modify('sunday this week');

        $today = new \DateTimeImmutable('today');

        $events = $eventRepository->findWithStaffingRequirementsOverlapping($gridStart, $gridEnd);

        $eventIds = array_map(static fn (Event $e) => $e->getId(), $events);
        $staffing = $attendeeRepository->findStaffingForEventsInRange($eventIds, $gridStart, $gridEnd);

        $coverageByOccurrence = [];
        $pendingCountByOccurrence = [];
        foreach ($staffing as $attendee) {
            $key = $this->occurrenceKey($attendee->getEvent(), $attendee->getOccurrenceDate() ?? $attendee->getEvent()->getDate());

            if ($attendee->isStaffingApproved()) {
                $requirementId = $attendee->getStaffingRequirement()->getId();
                $coverageByOccurrence[$key][$requirementId] ??= 0;
                $coverageByOccurrence[$key][$requirementId]++;
            } elseif ($attendee->isStaffingPending()) {
                $pendingCountByOccurrence[$key] ??= 0;
                $pendingCountByOccurrence[$key]++;
            }
        }

        $occurrencesByDay = [];
        $period = new \DatePeriod($gridStart, new \DateInterval('P1D'), $gridEnd->modify('+1 day'));
        foreach ($period as $day) {
            $dayOccurrences = [];

            if ($day < $today) {
                $occurrencesByDay[$day->format('Y-m-d')] = $dayOccurrences;
                continue;
            }

            foreach ($events as $event) {
                if (!$event->isValidForDate($day)) {
                    continue;
                }

                $key = $this->occurrenceKey($event, $day);
                $coverage   = [];
                $shortfalls = [];
                foreach ($event->getStaffingRequirements() as $requirement) {
                    $have = $coverageByOccurrence[$key][$requirement->getId()] ?? 0;
                    $coverage[] = ['requirement' => $requirement, 'have' => $have];
                    if ($have < $requirement->getMinCount()) {
                        $shortfalls[] = $requirement;
                    }
                }

                $dayOccurrences[] = [
                    'event'        => $event,
                    'date'         => $day,
                    'coverage'     => $coverage,
                    'shortfalls'   => $shortfalls,
                    'pendingCount' => $pendingCountByOccurrence[$key] ?? 0,
                ];
            }
            $occurrencesByDay[$day->format('Y-m-d')] = $dayOccurrences;
        }

        return $this->render('admin/rota/index.html.twig', [
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

    private function occurrenceKey(Event $event, \DateTimeImmutable $date): string
    {
        return $event->getId() . ':' . $date->format('Y-m-d');
    }

    #[Route('/{id}/edit', name: 'app_admin_event_edit', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function edit(
        Request $request,
        Event $event,
        EntityManagerInterface $em,
        CertificationRepository $certificationRepository,
    ): Response {
        $allCertifications = $certificationRepository->findBy([], ['name' => 'ASC']);
        $error = null;

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('admin_event_edit_' . $event->getId(), $request->request->get('_csrf_token'))) {
                $this->addFlash('error', 'Access denied.');
                return $this->redirectToRoute('app_home');
            }

            $error = $this->applyRequestToEvent($request, $event, $allCertifications);

            if (!$error) {
                $this->applyStaffingRequirements($request, $event, $allCertifications, $em);
                $em->flush();

                $this->addFlash('success', 'Event updated.');
                return $this->redirectToRoute('app_admin_event_show', ['id' => $event->getId()]);
            }
        }

        return $this->render('admin/events/edit.html.twig', [
            'event'          => $event,
            'error'          => $error,
            'certifications' => $allCertifications,
        ]);
    }

    #[Route('/{id}/delete', name: 'app_admin_event_delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function delete(Request $request, Event $event, EntityManagerInterface $em): Response
    {
        if (!$this->isCsrfTokenValid('delete_event_' . $event->getId(), $request->request->get('_csrf_token'))) {
            $this->addFlash('error', 'Access denied.');
            return $this->redirectToRoute('app_home');
        }

        $em->remove($event);
        $em->flush();

        $this->addFlash('success', 'Event deleted.');
        return $this->redirectToRoute('app_admin_events');
    }

    /** @param \App\Entity\Certification[] $allCertifications */
    private function applyRequestToEvent(Request $request, Event $event, array $allCertifications): ?string
    {
        $title    = trim($request->request->get('title', ''));
        $location = trim($request->request->get('location', ''));
        $dateRaw  = $request->request->get('date', '');
        $timeFrom = trim($request->request->get('timeFrom', ''));
        $timeTo   = trim($request->request->get('timeTo', ''));

        if ($title === '' || $location === '' || $dateRaw === '' || $timeFrom === '' || $timeTo === '') {
            return 'Title, location, date, and start/end time are required.';
        }

        try {
            $date = new \DateTimeImmutable($dateRaw);
        } catch (\Exception) {
            return 'Please enter a valid date.';
        }

        $isRecurring = $request->request->has('isRecurring');
        $recurUntil  = null;

        if ($isRecurring) {
            $recurUntilRaw = $request->request->get('recurUntil', '');
            if ($recurUntilRaw === '') {
                return 'Please set a "recurs until" date for a recurring event.';
            }
            try {
                $recurUntil = new \DateTimeImmutable($recurUntilRaw);
            } catch (\Exception) {
                return 'Please enter a valid "recurs until" date.';
            }
            if ($recurUntil < $date) {
                return 'The "recurs until" date must be on or after the event date.';
            }
            if (!$request->request->all('recurDays')) {
                return 'Please select at least one day for a recurring event.';
            }
        }

        $maxAttendeesRaw = trim($request->request->get('maxAttendees', ''));
        $priceRaw        = trim($request->request->get('price', ''));

        $event->setTitle($title);
        $event->setDescription(trim($request->request->get('description', '')) ?: null);
        $event->setAttendeeInfo(trim($request->request->get('attendeeInfo', '')) ?: null);
        $event->setDate($date);
        $event->setTimeFrom($timeFrom);
        $event->setTimeTo($timeTo);
        $event->setLocation($location);
        $event->setExternalUrl(trim($request->request->get('externalUrl', '')) ?: null);
        $event->setMaxAttendees($maxAttendeesRaw !== '' ? (int) $maxAttendeesRaw : null);
        $event->setPrice($priceRaw !== '' ? number_format((float) $priceRaw, 2, '.', '') : null);
        $event->setStatus($request->request->has('published') ? Event::STATUS_PUBLISHED : Event::STATUS_DRAFT);

        $event->setIsRecurring($isRecurring);
        $event->setRecurUntil($isRecurring ? $recurUntil : null);
        $event->setRecurDaysArray($isRecurring ? array_map('intval', $request->request->all('recurDays')) : []);

        $submittedCertIds = array_map('intval', $request->request->all('restrictions'));
        foreach ($event->getRestrictions()->toArray() as $certification) {
            if (!in_array($certification->getId(), $submittedCertIds, true)) {
                $event->removeRestriction($certification);
            }
        }
        foreach ($allCertifications as $certification) {
            if (in_array($certification->getId(), $submittedCertIds, true)) {
                $event->addRestriction($certification);
            }
        }

        return null;
    }

    /**
     * Syncs the event's staffing requirements from submitted "staffing_<certificationId>" min-count
     * fields — 0 or blank removes the requirement, a positive number creates or updates it.
     *
     * @param \App\Entity\Certification[] $allCertifications
     */
    private function applyStaffingRequirements(Request $request, Event $event, array $allCertifications, EntityManagerInterface $em): void
    {
        foreach ($allCertifications as $certification) {
            $minCountRaw = trim($request->request->get('staffing_' . $certification->getId(), ''));
            $minCount    = $minCountRaw !== '' ? max(0, (int) $minCountRaw) : 0;

            $requirement = $event->getStaffingRequirementFor($certification);

            if ($minCount > 0) {
                if (!$requirement) {
                    $requirement = new EventStaffingRequirement();
                    $requirement->setEvent($event);
                    $requirement->setCertification($certification);
                    $event->getStaffingRequirements()->add($requirement);
                    $em->persist($requirement);
                }
                $requirement->setMinCount($minCount);
            } elseif ($requirement) {
                $event->getStaffingRequirements()->removeElement($requirement);
                $em->remove($requirement);
            }
        }
    }

    /**
     * Which occurrence to show on the event info screen: the requested date if it's a real
     * occurrence, otherwise the next upcoming one, falling back to the most recent past
     * occurrence once the recurrence window has ended.
     */
    private function resolveOccurrenceDate(Event $event, ?\DateTimeImmutable $requested): \DateTimeImmutable
    {
        if ($requested !== null && $event->isValidForDate($requested)) {
            return $requested;
        }

        $today      = new \DateTimeImmutable('today');
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

    /** The previous/next valid occurrence date relative to $from, or null if there isn't one. */
    private function adjacentOccurrence(Event $event, \DateTimeImmutable $from, int $direction): ?\DateTimeImmutable
    {
        $step      = $direction >= 0 ? '+1 day' : '-1 day';
        $candidate = $from;

        for ($i = 0; $i < 400; $i++) {
            $candidate = $candidate->modify($step);

            if ($candidate < $event->getDate()) {
                return null;
            }
            if ($event->getRecurUntil() && $candidate > $event->getRecurUntil()) {
                return null;
            }
            if ($event->isValidForDate($candidate)) {
                return $candidate;
            }
        }

        return null;
    }
}
