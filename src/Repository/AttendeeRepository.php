<?php

namespace App\Repository;

use App\Entity\Attendee;
use App\Entity\Event;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Attendee>
 */
class AttendeeRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Attendee::class);
    }

    /** This member's non-cancelled booking for the given event/occurrence, if any. */
    public function findActiveBooking(Event $event, User $user, ?\DateTimeImmutable $occurrenceDate): ?Attendee
    {
        $qb = $this->createQueryBuilder('a')
            ->where('a.event = :event')
            ->andWhere('a.user = :user')
            ->andWhere('a.status != :cancelled')
            ->setParameter('event', $event)
            ->setParameter('user', $user)
            ->setParameter('cancelled', Attendee::STATUS_CANCELLED)
            ->setMaxResults(1);

        $this->whereOccurrence($qb, $occurrenceDate);

        return $qb->getQuery()->getOneOrNullResult();
    }

    /**
     * How many non-cancelled, non-staffing bookings exist for the given event/occurrence — i.e.
     * how many of maxAttendees' seats are actually taken. Staff aren't an audience seat, so they're
     * excluded here, not just from the admin display — this is the real capacity gate every booking
     * path (public booking, cart checkout, admin reinstate) checks against.
     */
    public function countActiveForOccurrence(Event $event, ?\DateTimeImmutable $occurrenceDate): int
    {
        $qb = $this->createQueryBuilder('a')
            ->select('COUNT(a.id)')
            ->where('a.event = :event')
            ->andWhere('a.status != :cancelled')
            ->andWhere('a.staffingRequirement IS NULL')
            ->setParameter('event', $event)
            ->setParameter('cancelled', Attendee::STATUS_CANCELLED);

        $this->whereOccurrence($qb, $occurrenceDate);

        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    /** How many non-cancelled bookings this member has on this specific occurrence (or, for a one-off event, the event itself) — e.g. several anonymous seats booked under them in one qty>1 line. */
    public function countActiveForUserOccurrence(Event $event, User $user, ?\DateTimeImmutable $occurrenceDate): int
    {
        $qb = $this->createQueryBuilder('a')
            ->select('COUNT(a.id)')
            ->where('a.event = :event')
            ->andWhere('a.user = :user')
            ->andWhere('a.status != :cancelled')
            ->setParameter('event', $event)
            ->setParameter('user', $user)
            ->setParameter('cancelled', Attendee::STATUS_CANCELLED);

        $this->whereOccurrence($qb, $occurrenceDate);

        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    /**
     * All non-cancelled bookings for the given events whose occurrence falls within the
     * given range, for batch-computing per-occurrence counts/booked-state in one query
     * instead of one query per occurrence.
     *
     * @param int[] $eventIds
     * @return Attendee[]
     */
    public function findActiveForEventsInRange(array $eventIds, \DateTimeImmutable $rangeStart, \DateTimeImmutable $rangeEnd): array
    {
        if (!$eventIds) {
            return [];
        }

        return $this->createQueryBuilder('a')
            ->innerJoin('a.event', 'e')->addSelect('e')
            ->where('a.event IN (:eventIds)')
            ->andWhere('a.status != :cancelled')
            ->andWhere('(a.occurrenceDate BETWEEN :start AND :end) OR (a.occurrenceDate IS NULL AND e.date BETWEEN :start AND :end)')
            ->setParameter('eventIds', $eventIds)
            ->setParameter('cancelled', Attendee::STATUS_CANCELLED)
            ->setParameter('start', $rangeStart)
            ->setParameter('end', $rangeEnd)
            ->getQuery()
            ->getResult();
    }

    /** Non-cancelled staffing bookings (any staffing status) for one occurrence, for the admin rota view. */
    public function findStaffingForOccurrence(Event $event, ?\DateTimeImmutable $occurrenceDate): array
    {
        $qb = $this->createQueryBuilder('a')
            ->innerJoin('a.user', 'u')->addSelect('u')
            ->innerJoin('a.staffingRequirement', 'r')->addSelect('r')
            ->innerJoin('r.certification', 'c')->addSelect('c')
            ->where('a.event = :event')
            // A declined staffing invite is cancelled as a booking (it never becomes a real one),
            // but it should still show up here under "Declined" — only a plain cancelled booking
            // (not a staffing one at all) should drop out.
            ->andWhere('a.status != :cancelled OR a.staffingStatus = :declined')
            ->setParameter('event', $event)
            ->setParameter('cancelled', Attendee::STATUS_CANCELLED)
            ->setParameter('declined', Attendee::STAFFING_DECLINED)
            ->orderBy('c.name', 'ASC')
            ->addOrderBy('a.createdAt', 'ASC');

        $this->whereOccurrence($qb, $occurrenceDate);

        return $qb->getQuery()->getResult();
    }

    /**
     * Non-cancelled staffing bookings for the given events whose occurrence falls within the
     * given range, for batch-computing rota coverage across a calendar grid in one query.
     *
     * @param int[] $eventIds
     * @return Attendee[]
     */
    public function findStaffingForEventsInRange(array $eventIds, \DateTimeImmutable $rangeStart, \DateTimeImmutable $rangeEnd): array
    {
        if (!$eventIds) {
            return [];
        }

        return $this->createQueryBuilder('a')
            ->innerJoin('a.event', 'e')->addSelect('e')
            ->innerJoin('a.staffingRequirement', 'r')->addSelect('r')
            ->where('a.event IN (:eventIds)')
            ->andWhere('a.status != :cancelled')
            ->andWhere('(a.occurrenceDate BETWEEN :start AND :end) OR (a.occurrenceDate IS NULL AND e.date BETWEEN :start AND :end)')
            ->setParameter('eventIds', $eventIds)
            ->setParameter('cancelled', Attendee::STATUS_CANCELLED)
            ->setParameter('start', $rangeStart)
            ->setParameter('end', $rangeEnd)
            ->getQuery()
            ->getResult();
    }

    /** Every booking (any status) for the given event, earliest occurrence first. */
    public function findForEvent(Event $event): array
    {
        return $this->createQueryBuilder('a')
            ->innerJoin('a.user', 'u')->addSelect('u')
            ->where('a.event = :event')
            ->setParameter('event', $event)
            ->orderBy('a.occurrenceDate', 'ASC')
            ->addOrderBy('a.createdAt', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /** Every booking (any status) for one occurrence of a recurring event. */
    public function findForEventOccurrence(Event $event, \DateTimeImmutable $occurrenceDate): array
    {
        return $this->createQueryBuilder('a')
            ->innerJoin('a.user', 'u')->addSelect('u')
            ->where('a.event = :event')
            ->andWhere('a.occurrenceDate = :occurrenceDate')
            ->setParameter('event', $event)
            ->setParameter('occurrenceDate', $occurrenceDate)
            ->orderBy('a.createdAt', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Distinct, non-cancelled attendees of an event, for emailing.
     *
     * - $upcomingOnly true: everyone booked onto any occurrence today or later (whole series).
     * - $upcomingOnly false + $occurrenceDate given: just that occurrence.
     * - $upcomingOnly false + $occurrenceDate null: the event's one-off booking (occurrenceDate IS NULL).
     *
     * @return User[]
     */
    public function findAttendeeUsersForEvent(Event $event, ?\DateTimeImmutable $occurrenceDate, bool $upcomingOnly): array
    {
        // Rooted at User (rather than the usual Attendee root) since Doctrine won't let a DQL
        // query select an entity that isn't the root/from alias.
        $qb = $this->getEntityManager()->createQueryBuilder()
            ->select('DISTINCT u')
            ->from(User::class, 'u')
            ->innerJoin(Attendee::class, 'a', 'WITH', 'a.user = u')
            ->where('a.event = :event')
            ->andWhere('a.status != :cancelled')
            ->setParameter('event', $event)
            ->setParameter('cancelled', Attendee::STATUS_CANCELLED);

        if ($upcomingOnly) {
            $qb->andWhere('a.occurrenceDate IS NULL OR a.occurrenceDate >= :today')
               ->setParameter('today', new \DateTimeImmutable('today'));
        } else {
            $this->whereOccurrence($qb, $occurrenceDate);
        }

        return $qb->getQuery()->getResult();
    }

    /** All of this member's bookings, including cancelled ones, newest first. */
    public function findAllForUser(User $user): array
    {
        return $this->createQueryBuilder('a')
            ->innerJoin('a.event', 'e')->addSelect('e')
            ->where('a.user = :user')
            ->setParameter('user', $user)
            ->orderBy('a.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /** @return Attendee[] */
    public function search(string $query = ''): array
    {
        $qb = $this->createQueryBuilder('a')
            ->innerJoin('a.user', 'u')->addSelect('u')
            ->innerJoin('a.event', 'e')->addSelect('e')
            ->orderBy('a.createdAt', 'DESC');

        if ($query !== '') {
            $qb->andWhere('u.firstName LIKE :q OR u.lastName LIKE :q OR CONCAT(u.firstName, \' \', u.lastName) LIKE :q OR u.email LIKE :q OR e.title LIKE :q')
               ->setParameter('q', '%' . $query . '%');
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * Every confirmed booking on a self-access event whose session date is yesterday or later —
     * the candidate pool a door's credential sync filters down to its own near-future window.
     * Yesterday rather than today so a session running past midnight UTC isn't dropped early.
     * Small enough in practice (one climbing wall, one door) to filter the actual time window in
     * memory rather than in SQL, since valid_from/valid_until are derived from the event's
     * schedule, not stored columns.
     *
     * @return Attendee[]
     */
    public function findConfirmedSelfAccessAttendees(): array
    {
        return $this->createQueryBuilder('a')
            ->innerJoin('a.event', 'e')->addSelect('e')
            ->innerJoin('a.user', 'u')->addSelect('u') // eager-loaded since findCredentialsForDoor() reads $attendee->getUser() for every row
            ->where('e.isSelfAccess = true')
            ->andWhere('a.status = :confirmed')
            ->andWhere('COALESCE(a.occurrenceDate, e.date) >= :since')
            ->setParameter('confirmed', Attendee::STATUS_CONFIRMED)
            ->setParameter('since', new \DateTimeImmutable('yesterday'), Types::DATE_IMMUTABLE)
            ->getQuery()
            ->getResult();
    }

    /**
     * {user}'s non-cancelled bookings checked in no earlier than {checkedInSince}, most recent
     * check-in first — the candidates for the session an exit-reader tap belongs to
     * (door-access-spec.md § Exit reader; DoorAccessService::applyMemberExitEvent() picks one).
     * The lower bound stops a stale session (someone who left by the push button days ago and
     * never tapped out) being closed with today's time.
     *
     * @return Attendee[]
     */
    public function findRecentCheckInsForUser(User $user, \DateTimeImmutable $checkedInSince): array
    {
        return $this->createQueryBuilder('a')
            ->innerJoin('a.event', 'e')->addSelect('e')
            ->where('a.user = :user')
            ->andWhere('a.checkedInAt >= :since')
            ->andWhere('a.status != :cancelled')
            ->setParameter('user', $user)
            ->setParameter('since', $checkedInSince)
            ->setParameter('cancelled', Attendee::STATUS_CANCELLED)
            ->orderBy('a.checkedInAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * {user}'s confirmed bookings (any event, checked in or not) whose session date is on or after
     * {from}'s date — for working out which session an exit tap falls in
     * (DoorAccessService::findSessionForExit()).
     *
     * @return Attendee[]
     */
    public function findConfirmedForUserFrom(User $user, \DateTimeImmutable $from): array
    {
        return $this->createQueryBuilder('a')
            ->innerJoin('a.event', 'e')->addSelect('e')
            ->where('a.user = :user')
            ->andWhere('a.status = :confirmed')
            ->andWhere('COALESCE(a.occurrenceDate, e.date) >= :from')
            ->setParameter('user', $user)
            ->setParameter('confirmed', Attendee::STATUS_CONFIRMED)
            ->setParameter('from', $from->setTime(0, 0), Types::DATE_IMMUTABLE)
            ->getQuery()
            ->getResult();
    }

    private function whereOccurrence(QueryBuilder $qb, ?\DateTimeImmutable $occurrenceDate): void
    {
        if ($occurrenceDate !== null) {
            $qb->andWhere('a.occurrenceDate = :occurrenceDate')->setParameter('occurrenceDate', $occurrenceDate);
        } else {
            $qb->andWhere('a.occurrenceDate IS NULL');
        }
    }
}
