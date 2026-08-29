<?php

namespace App\Repository;

use App\Entity\Payment;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Payment>
 */
class PaymentRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Payment::class);
    }

    /**
     * Admin payments list — searchable by member name/email or Stripe payment intent ID, with an
     * optional createdAt date range. $from/$to are inclusive.
     *
     * @return Payment[]
     */
    public function search(string $query = '', ?\DateTimeImmutable $from = null, ?\DateTimeImmutable $to = null): array
    {
        $qb = $this->createQueryBuilder('p')
            ->innerJoin('p.user', 'u')->addSelect('u')
            ->orderBy('p.createdAt', 'DESC');

        if ($query !== '') {
            $qb->andWhere('u.firstName LIKE :q OR u.lastName LIKE :q OR CONCAT(u.firstName, \' \', u.lastName) LIKE :q OR u.email LIKE :q OR p.stripePaymentIntentId LIKE :q')
               ->setParameter('q', '%' . $query . '%');
        }

        if ($from !== null) {
            $qb->andWhere('p.createdAt >= :from')->setParameter('from', $from);
        }

        if ($to !== null) {
            $qb->andWhere('p.createdAt <= :to')->setParameter('to', $to);
        }

        return $qb->getQuery()->getResult();
    }
}
