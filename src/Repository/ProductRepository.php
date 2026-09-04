<?php

namespace App\Repository;

use App\Entity\Product;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Product>
 */
class ProductRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Product::class);
    }

    /** @return Product[] */
    public function search(string $query = ''): array
    {
        $qb = $this->createQueryBuilder('p')
            ->orderBy('p.name', 'ASC')
            ->addOrderBy('p.variantValue', 'ASC');

        if ($query !== '') {
            $qb->andWhere('p.name LIKE :q OR p.variantValue LIKE :q')
                ->setParameter('q', '%' . $query . '%');
        }

        return $qb->getQuery()->getResult();
    }
}
