<?php

namespace App\Repository;

use App\Entity\PurchaseOrder;
use App\Entity\PurchaseOrderLine;
use App\Entity\StockProduct;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<PurchaseOrderLine>
 */
class PurchaseOrderLineRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PurchaseOrderLine::class);
    }

    /** @return PurchaseOrderLine[] {stockProduct}'s lines on POs that are approved or ordered and still waiting on delivery — for "on order" on the product page. */
    public function findOnOrder(StockProduct $stockProduct): array
    {
        $lines = $this->createQueryBuilder('l')
            ->join('l.purchaseOrder', 'po')
            ->addSelect('po')
            ->where('l.stockProduct = :stockProduct')
            ->andWhere('po.status IN (:statuses)')
            ->setParameter('stockProduct', $stockProduct)
            ->setParameter('statuses', [PurchaseOrder::STATUS_APPROVED, PurchaseOrder::STATUS_ORDERED, PurchaseOrder::STATUS_PART_RECEIVED])
            ->orderBy('po.expectedAt', 'ASC')
            ->getQuery()
            ->getResult();

        return array_values(array_filter($lines, static fn (PurchaseOrderLine $l) => $l->getQtyOutstanding() > 0));
    }

    public function existsForStockProduct(StockProduct $stockProduct): bool
    {
        return (bool) $this->createQueryBuilder('l')
            ->select('COUNT(l.id)')
            ->where('l.stockProduct = :stockProduct')
            ->setParameter('stockProduct', $stockProduct)
            ->getQuery()
            ->getSingleScalarResult();
    }
}
