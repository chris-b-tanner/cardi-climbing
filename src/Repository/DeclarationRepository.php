<?php

namespace App\Repository;

use App\Entity\Declaration;
use App\Entity\UserCertification;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Declaration>
 */
class DeclarationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Declaration::class);
    }

    /** Whether any member has already agreed to this declaration when completing a certification. */
    public function isInUse(Declaration $declaration): bool
    {
        $count = $this->getEntityManager()->createQueryBuilder()
            ->select('COUNT(uc.id)')
            ->from(UserCertification::class, 'uc')
            ->innerJoin('uc.agreedDeclarations', 'd')
            ->where('d = :declaration')
            ->setParameter('declaration', $declaration)
            ->getQuery()
            ->getSingleScalarResult();

        return $count > 0;
    }
}
