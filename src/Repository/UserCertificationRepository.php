<?php

namespace App\Repository;

use App\Entity\Certification;
use App\Entity\User;
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

    /**
     * @param string $status One of UserCertification::STATUS_* or '' for all statuses. Status isn't
     *                       a stored column (see UserCertification::getStatus()) so it's translated
     *                       into the equivalent timestamp conditions here.
     * @return UserCertification[]
     */
    public function search(string $query = '', string $status = ''): array
    {
        $qb = $this->createQueryBuilder('uc')
            ->innerJoin('uc.user', 'u')->addSelect('u')
            ->innerJoin('uc.certification', 'c')->addSelect('c')
            ->orderBy('uc.startedAt', 'DESC');

        if ($query !== '') {
            $qb->andWhere('u.firstName LIKE :q OR u.lastName LIKE :q OR CONCAT(u.firstName, \' \', u.lastName) LIKE :q OR u.email LIKE :q OR c.name LIKE :q')
               ->setParameter('q', '%' . $query . '%');
        }

        match ($status) {
            UserCertification::STATUS_IN_PROGRESS => $qb->andWhere('uc.cancelledAt IS NULL AND uc.approvedAt IS NULL AND uc.completedAt IS NULL'),
            UserCertification::STATUS_PENDING_APPROVAL => $qb->andWhere('uc.cancelledAt IS NULL AND uc.approvedAt IS NULL AND uc.completedAt IS NOT NULL'),
            UserCertification::STATUS_COMPLETED => $qb->andWhere('uc.cancelledAt IS NULL AND uc.approvedAt IS NOT NULL'),
            UserCertification::STATUS_CANCELLED => $qb->andWhere('uc.cancelledAt IS NOT NULL'),
            default => null,
        };

        return $qb->getQuery()->getResult();
    }

    /**
     * Distinct members who hold (i.e. have completed) the given certification.
     *
     * Rooted at User (rather than the usual UserCertification root) since Doctrine won't let a
     * DQL query select an entity that isn't the root/from alias.
     *
     * @return User[]
     */
    public function findHoldersForCertification(Certification $certification): array
    {
        return $this->getEntityManager()->createQueryBuilder()
            ->select('DISTINCT u')
            ->from(User::class, 'u')
            ->innerJoin(UserCertification::class, 'uc', 'WITH', 'uc.user = u')
            ->where('uc.certification = :certification')
            ->andWhere('uc.completedAt IS NOT NULL')
            ->setParameter('certification', $certification)
            ->getQuery()
            ->getResult();
    }
}
