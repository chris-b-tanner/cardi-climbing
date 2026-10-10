<?php

namespace App\Service;

use App\Entity\Event;
use App\Entity\Product;
use App\Entity\User;
use App\Repository\AttendeeRepository;
use App\Repository\ProductRepository;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * Resolving which occurrence of an event is meant, and building the bookability view of it that
 * the public calendar, event page, preview modal and landing page all render — so each of those
 * (and the admin event screens, for occurrence resolution) agree on what "next occurrence",
 * "full", "past" and "can book" mean.
 */
class EventOccurrenceService
{
    public function __construct(
        private readonly AttendeeRepository $attendeeRepository,
        private readonly ProductRepository $productRepository,
        private readonly CartService $cartService,
        private readonly Security $security,
    ) {}

    /**
     * Guards against the empty string specifically: DateTimeImmutable's constructor treats it like
     * "now" rather than throwing, so without this every caller's "no date given" case would silently
     * resolve to today instead of null.
     */
    public static function parseDate(string $raw): ?\DateTimeImmutable
    {
        if ($raw === '') {
            return null;
        }

        try {
            return new \DateTimeImmutable($raw);
        } catch (\Exception) {
            return null;
        }
    }

    /**
     * Which occurrence is meant: the requested date if it's a real occurrence of this event,
     * otherwise the next upcoming one, falling back to the most recent past occurrence if the
     * event (or its recurrence window) has already ended.
     */
    public function resolveOccurrenceDate(Event $event, ?\DateTimeImmutable $requested, ?\DateTimeImmutable $today = null): \DateTimeImmutable
    {
        if ($requested !== null && $event->isValidForDate($requested)) {
            return $requested;
        }

        if (!$event->isRecurring()) {
            return $event->getDate();
        }

        $searchFrom = max($event->getDate(), $today ?? new \DateTimeImmutable('today'));

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

    /**
     * The public calendar's week grid: each day from {weekStart} to {weekEnd} with the
     * occurrences of {events} that fall on it, in start-time order.
     *
     * @param Event[] $events
     * @return array<array{date: \DateTimeImmutable, events: array[]}>
     */
    public function buildWeek(array $events, \DateTimeImmutable $weekStart, \DateTimeImmutable $weekEnd, ?User $user): array
    {
        $today = new \DateTimeImmutable('today');

        // Fetch every booking for these events across the whole week in one query, then
        // derive per-occurrence counts/booked-state from it in memory — avoids running a
        // count + booking-lookup query for every single occurrence shown on the calendar.
        $eventIds        = array_map(static fn (Event $e) => $e->getId(), $events);
        $activeAttendees = $this->attendeeRepository->findActiveForEventsInRange($eventIds, $weekStart, $weekEnd);

        $occurrenceStats = [];
        foreach ($activeAttendees as $attendee) {
            $key = $this->occurrenceKey($attendee->getEvent(), $attendee->getOccurrenceDate() ?? $attendee->getEvent()->getDate());
            $occurrenceStats[$key] ??= ['count' => 0, 'bookedByUser' => false];
            $occurrenceStats[$key]['count']++;

            if ($user && $attendee->getUser()->getId() === $user->getId()) {
                $occurrenceStats[$key]['bookedByUser'] = true;
            }
        }

        $days   = [];
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

            usort($dayOccurrences, static fn (array $a, array $b) => $a['event']->getTimeFrom() <=> $b['event']->getTimeFrom());

            $days[] = ['date' => $day, 'events' => $dayOccurrences];
        }

        return $days;
    }

    /**
     * The view of a single occurrence for the event page, preview modal and landing page —
     * resolving {requestedDate} to a real occurrence first.
     *
     * @param bool $checkBookedByUser whether to look up if {user} already has a booking on it (the preview modal deliberately doesn't, so it still offers booking another place)
     * @param bool $withTicketContext whether to include the ticket-purchase context (see buildTicketContext()) and {user}'s existing booking count
     */
    public function buildPageView(Event $event, ?\DateTimeImmutable $requestedDate, ?User $user, bool $checkBookedByUser, bool $withTicketContext): array
    {
        $occurrenceDate       = $this->resolveOccurrenceDate($event, $requestedDate);
        $storedOccurrenceDate = $event->isRecurring() ? $occurrenceDate : null;

        $stats = [
            'count'        => $event->getMaxAttendees() !== null
                ? $this->attendeeRepository->countActiveForOccurrence($event, $storedOccurrenceDate)
                : 0,
            'bookedByUser' => $checkBookedByUser && $user !== null && $this->attendeeRepository->findActiveBooking($event, $user, $storedOccurrenceDate) !== null,
        ];

        $view = $this->buildOccurrenceView($event, $occurrenceDate, $user, $stats);

        if ($withTicketContext) {
            $view = array_merge($view, $this->buildTicketContext($event, $storedOccurrenceDate, $user));
            $view['existingBookingCount'] = $user !== null ? $this->attendeeRepository->countActiveForUserOccurrence($event, $user, $storedOccurrenceDate) : 0;
        }

        return $view;
    }

    /** @param array{count: int, bookedByUser: bool} $stats */
    public function buildOccurrenceView(Event $event, \DateTimeImmutable $date, ?User $user, array $stats): array
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
        // button's membership/credit hint even when the user is covered for free by their membership.
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
        // (EventController already gates that) — so team/admin can book onto it like any other
        // event, e.g. to put themselves on duty and build out the rota before publishing.
        $canBookUnpublished = !$event->isPublished() && $this->security->isGranted('ROLE_TEAM');

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

    /** The ticket-purchase context the preview modal and the public landing page both need — which active ticket products this event has, which of them {user} qualifies for, and how many are already in their cart for this occurrence. */
    private function buildTicketContext(Event $event, ?\DateTimeImmutable $storedOccurrenceDate, ?User $user): array
    {
        $eventTicketProducts = $this->productRepository->findActiveEventTickets($event);

        $ticketAccess = [];
        foreach ($eventTicketProducts as $ticketProduct) {
            $ticketAccess[$ticketProduct->getId()] = $this->userQualifiesForTicket($user, $ticketProduct);
        }

        $cartTicketCount = 0;
        if ($user !== null) {
            foreach ($this->cartService->getLines($user) as $line) {
                if (in_array($line['product'], $eventTicketProducts, true) && $line['occurrenceDate'] == $storedOccurrenceDate) {
                    $cartTicketCount++;
                }
            }
        }

        return [
            'eventTicketProducts' => $eventTicketProducts,
            'ticketAccess'        => $ticketAccess,
            'cartTicketCount'     => $cartTicketCount,
        ];
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

    private function occurrenceKey(Event $event, \DateTimeImmutable $date): string
    {
        return $event->getId() . '|' . ($event->isRecurring() ? $date->format('Y-m-d') : 'single');
    }
}
