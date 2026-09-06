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
    public function search(string $query = ''): array
    {
        $qb = $this->createQueryBuilder('p')
            ->orderBy('p.name', 'ASC')
            ->addOrderBy('p.variantValue', 'ASC');

        if ($query !== '') {
            $qb->andWhere('p.name LIKE :q OR p.variantValue LIKE :q')
                ->setParameter('q', '%' . $query . '%');
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

    /** Active products for the public self-serve shop — memberships and drop-in credits. Event tickets are sold from the event preview modal instead, one event at a time. */
    public function findActiveForShop(): array
    {
        return $this->createQueryBuilder('p')
            ->where('p.isActive = true')
            ->andWhere('p.productType IN (:types)')
            ->setParameter('types', [Product::TYPE_MEMBERSHIP, Product::TYPE_CREDIT])
            ->orderBy('p.productType', 'ASC')
            ->addOrderBy('p.name', 'ASC')
            ->addOrderBy('p.variantValue', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
