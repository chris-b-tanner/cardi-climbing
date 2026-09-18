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
     * optional createdAt date range and method filter. $from/$to are inclusive. There's no status
     * filter here: Payment::getStatus() is derived (from succeededAt/failedAt/refunds), not a
     * persisted column, so the controller filters by status in PHP after fetching.
     *
     * @return Payment[]
     */
    public function search(string $query = '', ?\DateTimeImmutable $from = null, ?\DateTimeImmutable $to = null, string $method = ''): array
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

        if ($method !== '') {
            $qb->andWhere('p.method = :method')->setParameter('method', $method);
        }

        return $qb->getQuery()->getResult();
    }
}
