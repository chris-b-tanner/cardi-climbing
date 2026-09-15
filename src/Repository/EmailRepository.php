<?php

namespace App\Repository;

use App\Entity\Email;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Email>
 */
class EmailRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Email::class);
    }

    /** Newest first, for the Settings > Emails list. */
    public function findForList(): array
    {
        return $this->createQueryBuilder('e')
            ->leftJoin('e.createdBy', 'c')->addSelect('c')
            ->leftJoin('e.sentBy', 's')->addSelect('s')
            ->orderBy('e.updatedAt', 'DESC')
            ->getQuery()
            ->getResult();
    }
}
