<?php

namespace App\Repository;

use App\Entity\NewsPost;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<NewsPost>
 */
class NewsPostRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, NewsPost::class);
    }

    /** @return NewsPost[] */
    public function findPublished(): array
    {
        return $this->createQueryBuilder('n')
            ->where('n.publishedAt IS NOT NULL')
            ->andWhere('n.publishedAt <= :now')
            ->setParameter('now', new \DateTimeImmutable())
            ->orderBy('n.publishedAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    public function findOnePublishedBySlug(string $slug): ?NewsPost
    {
        return $this->createQueryBuilder('n')
            ->where('n.slug = :slug')
            ->andWhere('n.publishedAt IS NOT NULL')
            ->andWhere('n.publishedAt <= :now')
            ->setParameter('slug', $slug)
            ->setParameter('now', new \DateTimeImmutable())
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /** @return NewsPost[] */
    public function findAllOrdered(): array
    {
        return $this->createQueryBuilder('n')
            ->orderBy('n.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }
}
