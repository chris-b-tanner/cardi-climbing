<?php

namespace App\Repository;

use App\Entity\Note;
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
