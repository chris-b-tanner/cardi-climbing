<?php

namespace App\Repository;

use App\Entity\UserCertification;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<UserCertification>
 */
class UserCertificationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, UserCertification::class);
    }

    /** @return UserCertification[] */
    public function search(string $query = ''): array
    {
        $qb = $this->createQueryBuilder('uc')
            ->innerJoin('uc.user', 'u')->addSelect('u')
            ->innerJoin('uc.certification', 'c')->addSelect('c')
            ->orderBy('uc.startedAt', 'DESC');

        if ($query !== '') {
            $qb->andWhere('u.firstName LIKE :q OR u.lastName LIKE :q OR CONCAT(u.firstName, \' \', u.lastName) LIKE :q OR u.email LIKE :q OR c.name LIKE :q')
               ->setParameter('q', '%' . $query . '%');
        }

        return $qb->getQuery()->getResult();
    }
}
