<?php

namespace App\Repository;

use App\Entity\CardLinkSession;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<CardLinkSession>
 */
class CardLinkSessionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CardLinkSession::class);
    }

    /** The one session the card station should currently be showing, if any — see card-setup.md's "only one pending session across the whole system" rule. */
    public function findOnePending(): ?CardLinkSession
    {
        return $this->findOneBy(['status' => CardLinkSession::STATUS_PENDING], ['createdAt' => 'DESC']);
    }

    /** {user}'s current pending session, if they're the one currently armed. */
    public function findPendingForUser(User $user): ?CardLinkSession
    {
        return $this->findOneBy(['user' => $user, 'status' => CardLinkSession::STATUS_PENDING]);
    }

    /** {user}'s most recent session of any status — what the admin browser's poll reports against. */
    public function findLatestForUser(User $user): ?CardLinkSession
    {
        return $this->findOneBy(['user' => $user], ['createdAt' => 'DESC']);
    }

    /** The most recent session of {mode} regardless of user — MODE_LOOKUP has no user to key by, so the admin browser's poll for it reports against this instead of findLatestForUser(). */
    public function findLatestByMode(string $mode): ?CardLinkSession
    {
        return $this->findOneBy(['mode' => $mode], ['createdAt' => 'DESC']);
    }

    /**
     * Every resolved session that actually read a UID (i.e. reached the station's scan handler) —
     * for the Settings > Cards report, which is built from this plus
     * AccessCardRepository::findAllOrdered(), not every armed-then-abandoned attempt. Capped
     * (unlike findAllOrdered(), which only grows as fast as real link/lock/unlock actions) since
     * this table grows one row per tap, including repeat test taps.
     */
    public function findAllWithScannedUid(int $limit = 500): array
    {
        return $this->createQueryBuilder('s')
            ->leftJoin('s.user', 'u')->addSelect('u')
            ->leftJoin('s.matchedUser', 'mu')->addSelect('mu')
            ->leftJoin('s.createdBy', 'cb')->addSelect('cb')
            ->where('s.scannedUid IS NOT NULL')
            ->orderBy('s.createdAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /** {uid}'s full scan history — every session that ever resolved with this UID tapped, most recent first. */
    public function findByScannedUid(string $uid): array
    {
        return $this->createQueryBuilder('s')
            ->leftJoin('s.user', 'u')->addSelect('u')
            ->leftJoin('s.matchedUser', 'mu')->addSelect('mu')
            ->leftJoin('s.createdBy', 'cb')->addSelect('cb')
            ->where('s.scannedUid = :uid')
            ->setParameter('uid', $uid)
            ->orderBy('s.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }
}
