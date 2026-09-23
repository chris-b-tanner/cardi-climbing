<?php

namespace App\Repository;

use App\Entity\Note;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Note>
 */
class NoteRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Note::class);
    }

    /** @return Note[] */
    public function findForNoteable(string $type, int $id): array
    {
        return $this->createQueryBuilder('n')
            ->where('n.noteableType = :type')
            ->andWhere('n.noteableId = :id')
            ->setParameter('type', $type)
            ->setParameter('id', $id)
            ->orderBy('n.pinned', 'DESC')
            ->addOrderBy('n.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    public function countForNoteable(string $type, int $id): int
    {
        return (int) $this->createQueryBuilder('n')
            ->select('COUNT(n.id)')
            ->where('n.noteableType = :type')
            ->andWhere('n.noteableId = :id')
            ->setParameter('type', $type)
            ->setParameter('id', $id)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /** How many recipients of a bulk Email have a confirmed "Emailed:" audit note so far — used to show delivery progress while the bulk_email queue is still draining (see AdminEmailController::compose()). */
    public function countForEmail(int $emailId): int
    {
        return (int) $this->createQueryBuilder('n')
            ->select('COUNT(n.id)')
            ->where('n.email = :emailId')
            ->setParameter('emailId', $emailId)
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function countPinnedFor(string $type, int $id): int
    {
        return (int) $this->createQueryBuilder('n')
            ->select('COUNT(n.id)')
            ->where('n.noteableType = :type')
            ->andWhere('n.noteableId = :id')
            ->andWhere('n.pinned = true')
            ->setParameter('type', $type)
            ->setParameter('id', $id)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * The most recent turn in {user}'s ad hoc email conversation — whichever side spoke last,
     * admin or member (see Note::$emailSubject) — strictly by time, ignoring pinned status (unlike
     * findForNoteable()'s display ordering, this is used to continue a subject line / find what to
     * quote, not to decide what to show first).
     */
    public function findLatestEmailThreadNote(User $user): ?Note
    {
        return $this->createQueryBuilder('n')
            ->where('n.noteableType = :type')
            ->andWhere('n.noteableId = :id')
            ->andWhere('n.emailSubject IS NOT NULL')
            ->setParameter('type', Note::TYPE_MEMBER)
            ->setParameter('id', $user->getId())
            ->orderBy('n.createdAt', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /** Every pinned note across the system, oldest pinned first — the shared "Actions" task list. */
    public function findAllPinned(): array
    {
        return $this->createQueryBuilder('n')
            ->where('n.pinned = true')
            ->orderBy('n.pinnedAt', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
