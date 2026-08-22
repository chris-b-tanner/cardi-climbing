<?php

namespace App\Repository;

use App\Entity\Attendee;
use App\Entity\Event;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
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

    /** How many non-cancelled bookings exist for the given event/occurrence. */
    public function countActiveForOccurrence(Event $event, ?\DateTimeImmutable $occurrenceDate): int
    {
        $qb = $this->createQueryBuilder('a')
            ->select('COUNT(a.id)')
            ->where('a.event = :event')
            ->andWhere('a.status != :cancelled')
            ->setParameter('event', $event)
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

    private function whereOccurrence(QueryBuilder $qb, ?\DateTimeImmutable $occurrenceDate): void
    {
        if ($occurrenceDate !== null) {
            $qb->andWhere('a.occurrenceDate = :occurrenceDate')->setParameter('occurrenceDate', $occurrenceDate);
        } else {
            $qb->andWhere('a.occurrenceDate IS NULL');
        }
    }
}
