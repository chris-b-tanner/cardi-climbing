<?php

namespace App\Repository;

use App\Entity\AccessEvent;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<AccessEvent>
 */
class AccessEventRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AccessEvent::class);
    }

    public function findOneByEventId(string $eventId): ?AccessEvent
    {
        return $this->findOneBy(['eventId' => $eventId]);
    }

    /** Newest first, for the admin report — see door-access-spec.md's open item on this being unpaginated for now, fine at this venue's scale. */
    public function findForReport(int $limit = 200): array
    {
        return $this->createQueryBuilder('ae')
            ->leftJoin('ae.attendee', 'a')->addSelect('a')
            ->leftJoin('a.user', 'au')->addSelect('au')
            ->leftJoin('ae.keyholderUser', 'ku')->addSelect('ku')
            ->leftJoin('ae.cardUser', 'cu')->addSelect('cu')
            ->orderBy('ae.createdAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }
}
