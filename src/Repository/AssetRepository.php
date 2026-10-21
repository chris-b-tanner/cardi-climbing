<?php

namespace App\Repository;

use App\Entity\Asset;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Asset>
 */
class AssetRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Asset::class);
    }

    /**
     * The register, filtered. $status '' means every current (not disposed) asset; pass
     * Asset::STATUS_DISPOSED to see only disposed ones, or 'all' for both.
     *
     * @return Asset[]
     */
    public function search(string $query = '', string $category = '', string $location = '', string $status = ''): array
    {
        $qb = $this->createQueryBuilder('a')
            ->orderBy('a.category', 'ASC')
            ->addOrderBy('a.name', 'ASC');

        if ($query !== '') {
            $qb->andWhere('a.name LIKE :q OR a.identifier LIKE :q OR a.description LIKE :q')
                ->setParameter('q', '%' . $query . '%');
        }

        if ($category !== '') {
            $qb->andWhere('a.category = :category')
                ->setParameter('category', $category);
        }

        if ($location !== '') {
            $qb->andWhere('a.location = :location')
                ->setParameter('location', $location);
        }

        if ($status === '') {
            $qb->andWhere('a.status != :disposed')
                ->setParameter('disposed', Asset::STATUS_DISPOSED);
        } elseif ($status !== 'all') {
            $qb->andWhere('a.status = :status')
                ->setParameter('status', $status);
        }

        return $qb->getQuery()->getResult();
    }

    /** @return string[] Every location in use, for the location filter and the form's suggestions. */
    public function findLocations(): array
    {
        return array_column(
            $this->createQueryBuilder('a')
                ->select('DISTINCT a.location')
                ->where('a.location IS NOT NULL')
                ->orderBy('a.location', 'ASC')
                ->getQuery()
                ->getScalarResult(),
            'location',
        );
    }
}
