<?php

namespace App\Repository;

use App\Entity\DoorLog;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<DoorLog>
 */
class DoorLogRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, DoorLog::class);
    }

    public function findOneByLogId(string $logId): ?DoorLog
    {
        return $this->findOneBy(['logId' => $logId]);
    }

    /** Whether an alert email has already gone out for {doorId} since {since} — the per-door cooldown that stops a stuck sensor from spamming an inbox (see § Server-side alerting). */
    public function hasRecentAlert(int $doorId, \DateTimeImmutable $since): bool
    {
        return (int) $this->createQueryBuilder('l')
            ->select('COUNT(l.id)')
            ->where('l.doorId = :doorId')
            ->andWhere('l.alertSentAt >= :since')
            ->setParameter('doorId', $doorId)
            ->setParameter('since', $since)
            ->getQuery()
            ->getSingleScalarResult() > 0;
    }

    /** Newest first — for an admin diagnostic view, if/when one is built (see door-access-spec.md's open items). */
    public function findForReport(int $limit = 200): array
    {
        return $this->createQueryBuilder('l')
            ->orderBy('l.receivedAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }
}
