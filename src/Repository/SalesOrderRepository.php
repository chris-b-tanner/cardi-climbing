<?php

namespace App\Repository;

use App\Entity\SalesOrder;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<SalesOrder>
 */
class SalesOrderRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SalesOrder::class);
    }

    /** @return SalesOrder[] */
    public function findAllOrdered(): array
    {
        return $this->createQueryBuilder('o')
            ->innerJoin('o.user', 'u')->addSelect('u')
            ->orderBy('o.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /** @return SalesOrder[] */
    /** @param string $status One of SalesOrder::STATUS_*, or '' for all statuses. */
    public function search(string $query = '', string $status = ''): array
    {
        $qb = $this->createQueryBuilder('o')
            ->innerJoin('o.user', 'u')->addSelect('u')
            ->orderBy('o.createdAt', 'DESC');

        if ($query !== '') {
            $qb->andWhere('u.firstName LIKE :q OR u.lastName LIKE :q OR CONCAT(u.firstName, \' \', u.lastName) LIKE :q OR u.email LIKE :q')
               ->setParameter('q', '%' . $query . '%');
        }

        if ($status !== '') {
            $qb->andWhere('o.status = :status')
               ->setParameter('status', $status);
        }

        return $qb->getQuery()->getResult();
    }
}
