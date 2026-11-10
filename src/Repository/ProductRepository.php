<?php

namespace App\Repository;

use App\Entity\Event;
use App\Entity\Product;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Product>
 */
class ProductRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Product::class);
    }

    /** @return Product[] */
    /** @param string $type One of Product::TYPE_*, or '' for all types. */
    public function search(string $query = '', string $type = ''): array
    {
        $qb = $this->createQueryBuilder('p')
            ->orderBy('p.name', 'ASC')
            ->addOrderBy('p.variantValue', 'ASC');

        if ($query !== '') {
            $qb->andWhere('p.name LIKE :q OR p.variantValue LIKE :q')
                ->setParameter('q', '%' . $query . '%');
        }

        if ($type !== '') {
            $qb->andWhere('p.productType = :type')
                ->setParameter('type', $type);
        }

        return $qb->getQuery()->getResult();
    }

    /** Active products for the POS product grid. */
    public function findActive(): array
    {
        return $this->createQueryBuilder('p')
            ->where('p.isActive = true')
            ->orderBy('p.name', 'ASC')
            ->addOrderBy('p.variantValue', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /** Active event-ticket products for {event} — an event can be sold via more than one (e.g. "Adult"/"Child"). */
    public function findActiveEventTickets(Event $event): array
    {
        return $this->createQueryBuilder('p')
            ->innerJoin('p.eventTicketProduct', 'etp')
            ->where('p.isActive = true')
            ->andWhere('etp.event = :event')
            ->setParameter('event', $event)
            ->orderBy('p.name', 'ASC')
            ->addOrderBy('p.variantValue', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Active products for the public self-serve shop: memberships, drop-in credits, stock items,
     * and event tickets. A ticket is only listed while its event is published and still has a date
     * to come. For a one-off event that means its date is today or later; for a recurring event
     * it means its recurUntil is open or today or later.
     */
    public function findActiveForShop(): array
    {
        $today = new \DateTimeImmutable('today');

        return $this->createQueryBuilder('p')
            ->leftJoin('p.eventTicketProduct', 'etp')
            ->leftJoin('etp.event', 'e')
            ->addSelect('etp', 'e')
            ->where('p.isActive = true')
            ->andWhere(
                'p.productType IN (:plainTypes)
                 OR (p.productType = :ticketType AND e.status = :published
                     AND ((e.isRecurring = false AND e.date >= :today)
                       OR (e.isRecurring = true AND (e.recurUntil IS NULL OR e.recurUntil >= :today))))'
            )
            ->setParameter('plainTypes', [Product::TYPE_MEMBERSHIP, Product::TYPE_CREDIT, Product::TYPE_STOCK])
            ->setParameter('ticketType', Product::TYPE_EVENT_TICKET)
            ->setParameter('published', Event::STATUS_PUBLISHED)
            ->setParameter('today', $today)
            ->orderBy('p.productType', 'ASC')
            ->addOrderBy('p.name', 'ASC')
            ->addOrderBy('p.variantValue', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
