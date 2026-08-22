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
     * Published events whose date (or, for recurring events, whose recurrence window)
     * overlaps the given range. Callers expand recurring rows into occurrences themselves
     * via Event::isValidForDate().
     *
     * @return Event[]
     */
    public function findPublishedOverlapping(\DateTimeImmutable $rangeStart, \DateTimeImmutable $rangeEnd): array
    {
        return $this->createQueryBuilder('e')
            ->where('e.status = :published')
            ->andWhere('
                (e.isRecurring = false AND e.date BETWEEN :start AND :end)
                OR
                (e.isRecurring = true AND e.date <= :end AND (e.recurUntil IS NULL OR e.recurUntil >= :start))
            ')
            ->setParameter('published', Event::STATUS_PUBLISHED)
            ->setParameter('start', $rangeStart)
            ->setParameter('end', $rangeEnd)
            ->orderBy('e.date', 'ASC')
            ->addOrderBy('e.timeFrom', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
