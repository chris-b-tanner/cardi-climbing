<?php

namespace App\Repository;

use App\Entity\Tag;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Tag>
 */
class TagRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Tag::class);
    }

    /**
     * Every tag with how many contacts carry it, and — for tags with a reminder interval — how many
     * of those are overdue a contact (same rule as Tag::isStale(), so the figure matches the yellow
     * rows on the members list filtered by that tag). Alphabetical by name. For the dashboard.
     *
     * @return array<int, array{tag: Tag, count: int, stale: ?int}>
     */
    public function findWithContactCounts(): array
    {
        $rows = $this->createQueryBuilder('t')
            ->select('t AS tag', 'COUNT(u.id) AS userCount')
            ->leftJoin('t.users', 'u')
            ->groupBy('t.id')
            ->orderBy('t.name', 'ASC')
            ->getQuery()
            ->getResult();

        $now = new \DateTimeImmutable();

        return array_map(function (array $row) use ($now): array {
            /** @var Tag $tag */
            $tag   = $row['tag'];
            $stale = null;

            if ($tag->getRemindAfterDays() !== null) {
                $stale = (int) $this->getEntityManager()->createQueryBuilder()
                    ->select('COUNT(u.id)')
                    ->from(User::class, 'u')
                    ->join('u.tags', 't')
                    ->where('t.id = :tagId')
                    ->andWhere('COALESCE(u.lastActivityAt, u.createdAt) < :cutoff')
                    ->setParameter('tagId', $tag->getId())
                    ->setParameter('cutoff', $now->modify('-' . $tag->getRemindAfterDays() . ' days'))
                    ->getQuery()
                    ->getSingleScalarResult();
            }

            return ['tag' => $tag, 'count' => (int) $row['userCount'], 'stale' => $stale];
        }, $rows);
    }
}
