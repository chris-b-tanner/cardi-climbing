<?php

namespace App\Repository;

use App\Entity\Attendee;
use App\Entity\Note;
use App\Entity\SalesOrder;
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

    /**
     * Open-tracking figures for a bulk Email: how many of its "Emailed:" notes were tracked (sent
     * with an emailRef — anything sent before tracking existed wasn't), and when each tracked
     * recipient first opened it. First opens only — we don't keep a timestamp for repeat opens.
     *
     * @return array{tracked: int, firstOpens: \DateTimeImmutable[]}
     */
    public function findOpenStatsForEmail(int $emailId): array
    {
        $rows = $this->createQueryBuilder('n')
            ->select('n.emailOpenedAt')
            ->where('n.email = :emailId')
            ->andWhere('n.emailRef IS NOT NULL')
            ->setParameter('emailId', $emailId)
            ->getQuery()
            ->getArrayResult();

        return [
            'tracked'    => count($rows),
            'firstOpens' => array_values(array_filter(array_column($rows, 'emailOpenedAt'))),
        ];
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

    /**
     * The most recent notes across the system, newest first — the "Recent updates" feed. $query
     * matches the note's own text (and email subject). $tagId/$assignedToId filter on the person
     * the note is about: the member for a member note, the booker for a booking note, the customer
     * for a sale note. Event and product notes aren't about a person, so either filter excludes
     * them. $assignedToId 0 = people with no assignee, same convention as UserRepository::search().
     * $addedById filters on who wrote the note itself; 0 = system-generated notes (no author).
     *
     * @return Note[]
     */
    public function findRecent(string $query = '', ?int $tagId = null, ?int $assignedToId = null, int $limit = 100, ?int $addedById = null): array
    {
        $qb = $this->createQueryBuilder('n')
            ->leftJoin('n.addedBy', 'ab')->addSelect('ab')
            ->leftJoin('n.assignedTo', 'at')->addSelect('at')
            ->orderBy('n.createdAt', 'DESC')
            ->addOrderBy('n.id', 'DESC')
            ->setMaxResults($limit);

        if ($query !== '') {
            $qb->andWhere('n.content LIKE :q OR n.emailSubject LIKE :q')
               ->setParameter('q', '%' . $query . '%');
        }

        if ($addedById === 0) {
            $qb->andWhere('n.addedBy IS NULL');
        } elseif ($addedById !== null) {
            $qb->andWhere('n.addedBy = :addedById')
               ->setParameter('addedById', $addedById);
        }

        if ($tagId !== null || $assignedToId !== null) {
            // The same "people matching the filters" subquery, once per note type that points at a
            // person — each needs its own aliases, since DQL aliases are shared across subqueries.
            $people = function (string $alias) use ($tagId, $assignedToId): string {
                $sub = $this->getEntityManager()->createQueryBuilder()
                    ->select($alias . '.id')
                    ->from(User::class, $alias);
                if ($tagId !== null) {
                    $sub->join($alias . '.tags', $alias . 't')->andWhere($alias . 't.id = :tagId');
                }
                if ($assignedToId === 0) {
                    $sub->andWhere($alias . '.assignedTo IS NULL');
                } elseif ($assignedToId !== null) {
                    $sub->andWhere($alias . '.assignedTo = :assignedToId');
                }
                return $sub->getDQL();
            };

            $attendees = $this->getEntityManager()->createQueryBuilder()
                ->select('a.id')->from(Attendee::class, 'a')
                ->where('a.user IN (' . $people('pa') . ')')->getDQL();
            $orders = $this->getEntityManager()->createQueryBuilder()
                ->select('o.id')->from(SalesOrder::class, 'o')
                ->where('o.user IN (' . $people('po') . ')')->getDQL();

            $qb->andWhere(
                '(n.noteableType = :typeMember AND n.noteableId IN (' . $people('pm') . '))'
                . ' OR (n.noteableType = :typeAttendee AND n.noteableId IN (' . $attendees . '))'
                . ' OR (n.noteableType = :typeOrder AND n.noteableId IN (' . $orders . '))'
            )
                ->setParameter('typeMember', Note::TYPE_MEMBER)
                ->setParameter('typeAttendee', Note::TYPE_ATTENDEE)
                ->setParameter('typeOrder', Note::TYPE_ORDER);

            if ($tagId !== null) {
                $qb->setParameter('tagId', $tagId);
            }
            if ($assignedToId !== null && $assignedToId !== 0) {
                $qb->setParameter('assignedToId', $assignedToId);
            }
        }

        return $qb->getQuery()->getResult();
    }
}
