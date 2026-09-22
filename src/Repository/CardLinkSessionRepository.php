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
}
