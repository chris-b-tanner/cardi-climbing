<?php

namespace App\Repository;

use App\Entity\Event;
use App\Entity\EventTicketProduct;
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
     * Events sold with no ticket product — the only ones an admin can check a member directly
     * onto, since an event with tickets is booked by selling one instead (via the shop/cart).
     *
     * @return Event[]
     */
    public function findWithoutTicketsOrdered(): array
    {
        return $this->createQueryBuilder('e')
            ->where('e.id NOT IN (SELECT IDENTITY(etp.event) FROM ' . EventTicketProduct::class . ' etp)')
            ->orderBy('e.date', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /** Whether {event} has any ticket product at all (active or not) — see findWithoutTicketsOrdered(). */
    public function hasAnyTicketProduct(Event $event): bool
    {
        return $this->getEntityManager()->getRepository(EventTicketProduct::class)->findOneBy(['event' => $event]) !== null;
    }

    /** @return Event[] */
    public function search(string $query = ''): array
    {
        $qb = $this->createQueryBuilder('e')
            ->orderBy('e.date', 'ASC');

        if ($query !== '') {
            $qb->andWhere('e.title LIKE :q OR e.location LIKE :q')
               ->setParameter('q', '%' . $query . '%');
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
     * @return Event[]
     */
    public function findPublishedOverlapping(\DateTimeImmutable $rangeStart, \DateTimeImmutable $rangeEnd, bool $includeDrafts = false): array
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
