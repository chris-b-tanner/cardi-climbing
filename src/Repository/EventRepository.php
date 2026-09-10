<?php

namespace App\Repository;

use App\Entity\Event;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Event>
 */
class EventRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Event::class);
    }

    /** @return Event[] */
    public function findAllOrdered(): array
    {
        return $this->createQueryBuilder('e')
            ->orderBy('e.date', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Events that don't accept a ticket as an access method — the only ones an admin can check a
     * member directly onto, since a ticket-accepting event is booked by selling one instead (via
     * the shop/cart) — whose date, or for a recurring event whose recurrence window, overlaps the
     * given range. No published-only filter: an admin can check a member into a draft event too.
     *
     * @return Event[]
     */
    public function findWithoutTicketAccessOverlapping(\DateTimeImmutable $rangeStart, \DateTimeImmutable $rangeEnd): array
    {
        return $this->createQueryBuilder('e')
            ->where("(e.accessMethods IS NULL OR e.accessMethods NOT LIKE '%" . Event::ACCESS_TICKET . "%')")
            ->andWhere('
                (e.isRecurring = false AND e.date BETWEEN :start AND :end)
                OR
                (e.isRecurring = true AND e.date <= :end AND (e.recurUntil IS NULL OR e.recurUntil >= :start))
            ')
            ->setParameter('start', $rangeStart)
            ->setParameter('end', $rangeEnd)
            ->orderBy('e.date', 'ASC')
            ->addOrderBy('e.timeFrom', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /** @return Event[] */
    /** Filter value for search() meaning "open, no access method at all" rather than one of Event::ACCESS_*. */
    public const ACCESS_FILTER_FREE = 'free';

    /** @param string $accessMethod One of Event::ACCESS_*, self::ACCESS_FILTER_FREE for events with no access method at all, or '' for all events regardless. */
    public function search(string $query = '', string $accessMethod = ''): array
    {
        $qb = $this->createQueryBuilder('e')
            ->orderBy('e.date', 'ASC');

        if ($query !== '') {
            $qb->andWhere('e.title LIKE :q OR e.location LIKE :q')
               ->setParameter('q', '%' . $query . '%');
        }

        if ($accessMethod === self::ACCESS_FILTER_FREE) {
            $qb->andWhere('e.accessMethods IS NULL');
        } elseif ($accessMethod !== '') {
            $qb->andWhere("e.accessMethods LIKE :access")
               ->setParameter('access', '%' . $accessMethod . '%');
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * Published events matching {query} by title that still have at least one occurrence today or
     * later — for the public calendar's search box, which finds an event by name regardless of
     * which week is currently being browsed rather than filtering within just one week.
     *
     * $includeDrafts lets team/admin also match unpublished events, same as findPublishedOverlapping().
     *
     * @return Event[]
     */
    public function searchUpcoming(string $query, \DateTimeImmutable $today, bool $includeDrafts = false): array
    {
        $qb = $this->createQueryBuilder('e')
            ->leftJoin('e.restrictions', 'r')->addSelect('r')
            ->where('e.title LIKE :q')
            ->andWhere('
                (e.isRecurring = false AND e.date >= :today)
                OR
                (e.isRecurring = true AND (e.recurUntil IS NULL OR e.recurUntil >= :today))
            ')
            ->setParameter('q', '%' . $query . '%')
            ->setParameter('today', $today)
            ->orderBy('e.date', 'ASC');

        if (!$includeDrafts) {
            $qb->andWhere('e.status = :published')->setParameter('published', Event::STATUS_PUBLISHED);
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * Events whose date (or, for recurring events, whose recurrence window) overlaps the given
     * range. Callers expand recurring rows into occurrences themselves via Event::isValidForDate().
     *
     * $includeDrafts lets team/admin viewers preview unpublished events on the public calendar
     * (e.g. to set up staffing requirements before publishing) — everyone else only sees published ones.
     *
     * $titleQuery, when given, restricts to events whose title matches — the calendar's search box
     * filters the week grid down to matching events rather than switching to a different layout.
     *
     * @return Event[]
     */
    public function findPublishedOverlapping(\DateTimeImmutable $rangeStart, \DateTimeImmutable $rangeEnd, bool $includeDrafts = false, string $titleQuery = ''): array
    {
        $qb = $this->createQueryBuilder('e')
            ->leftJoin('e.restrictions', 'r')->addSelect('r')
            ->where('
                (e.isRecurring = false AND e.date BETWEEN :start AND :end)
                OR
                (e.isRecurring = true AND e.date <= :end AND (e.recurUntil IS NULL OR e.recurUntil >= :start))
            ')
            ->setParameter('start', $rangeStart)
            ->setParameter('end', $rangeEnd)
            ->orderBy('e.date', 'ASC')
            ->addOrderBy('e.timeFrom', 'ASC');

        if (!$includeDrafts) {
            $qb->andWhere('e.status = :published')->setParameter('published', Event::STATUS_PUBLISHED);
        }

        if ($titleQuery !== '') {
            $qb->andWhere('e.title LIKE :titleQuery')->setParameter('titleQuery', '%' . $titleQuery . '%');
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * Events (published or draft) with at least one staffing requirement, overlapping the given
     * range — the candidate set for the admin rota calendar, which drafts are shown on so team/admin
     * can build out staffing before publishing.
     *
     * @return Event[]
     */
    public function findWithStaffingRequirementsOverlapping(\DateTimeImmutable $rangeStart, \DateTimeImmutable $rangeEnd): array
    {
        return $this->createQueryBuilder('e')
            ->innerJoin('e.staffingRequirements', 'sr')->addSelect('sr')
            ->innerJoin('sr.certification', 'c')->addSelect('c')
            ->where('
                (e.isRecurring = false AND e.date BETWEEN :start AND :end)
                OR
                (e.isRecurring = true AND e.date <= :end AND (e.recurUntil IS NULL OR e.recurUntil >= :start))
            ')
            ->setParameter('start', $rangeStart)
            ->setParameter('end', $rangeEnd)
            ->orderBy('e.date', 'ASC')
            ->addOrderBy('e.timeFrom', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
