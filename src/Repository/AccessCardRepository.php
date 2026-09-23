<?php

namespace App\Repository;

use App\Entity\AccessCard;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<AccessCard>
 */
class AccessCardRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AccessCard::class);
    }

    /** {user}'s current active (door-working) card, if any — excludes a locked card. A targeted query rather than loading the whole User::$accessCards history. */
    public function findActiveForUser(User $user): ?AccessCard
    {
        return $this->findOneBy(['user' => $user, 'status' => AccessCard::STATUS_ACTIVE]);
    }

    /** {user}'s current card whether active OR locked — i.e. not yet superseded by a replacement. Use this for "what card does this member currently have" UI/lock/unlock; use findActiveForUser() when door-working status specifically matters. */
    public function findCurrentForUser(User $user): ?AccessCard
    {
        return $this->createQueryBuilder('c')
            ->where('c.user = :user')
            ->andWhere('c.status IN (:statuses)')
            ->setParameter('user', $user)
            ->setParameter('statuses', [AccessCard::STATUS_ACTIVE, AccessCard::STATUS_LOCKED])
            ->getQuery()
            ->getOneOrNullResult();
    }

    /** Resolves a tapped/typed UID to its card regardless of status — a locked or replaced card still identifies who it belonged to (see card-setup.md's whole point of locking rather than deleting). */
    public function findOneByUid(string $uid): ?AccessCard
    {
        return $this->findOneBy(['uid' => $uid]);
    }

    /** Whether {uid} is already claimed by any card, of any status, other than {excludeCardId} — a locked/replaced card's UID stays reserved forever, never freed up for reuse by someone else. */
    public function uidExists(string $uid, ?int $excludeCardId = null): bool
    {
        $qb = $this->createQueryBuilder('c')
            ->select('COUNT(c.id)')
            ->where('c.uid = :uid')
            ->setParameter('uid', $uid);

        if ($excludeCardId !== null) {
            $qb->andWhere('c.id != :excludeId')->setParameter('excludeId', $excludeCardId);
        }

        return (int) $qb->getQuery()->getSingleScalarResult() > 0;
    }

    /** Every registered card, any status — for the Settings > Cards report. */
    public function findAllOrdered(): array
    {
        return $this->createQueryBuilder('c')
            ->leftJoin('c.user', 'u')->addSelect('u')
            ->leftJoin('c.deployedBy', 'db')->addSelect('db')
            ->leftJoin('c.lockedBy', 'lb')->addSelect('lb')
            ->leftJoin('c.unlockedBy', 'ub')->addSelect('ub')
            ->orderBy('c.deployedAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /** Cards with standing all-hours door access — see door-access-spec.md § All-hours cards. Active status only: a locked card is never all-hours regardless of the flag. */
    public function findAllHoursForDoor(): array
    {
        return $this->createQueryBuilder('c')
            ->leftJoin('c.user', 'u')->addSelect('u')
            ->where('c.status = :status')
            ->andWhere('c.allHoursAccess = true')
            ->setParameter('status', AccessCard::STATUS_ACTIVE)
            ->getQuery()
            ->getResult();
    }
}
