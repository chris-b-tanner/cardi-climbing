<?php

namespace App\Repository;

use App\Entity\PurchaseOrderReceipt;
use App\Entity\StockProduct;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<PurchaseOrderReceipt>
 */
class PurchaseOrderReceiptRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PurchaseOrderReceipt::class);
    }

    /** @return PurchaseOrderReceipt[] Every receipt of {stockProduct}, newest first — its purchase and cost history. */
    public function findForStockProduct(StockProduct $stockProduct): array
    {
        return $this->createQueryBuilder('r')
            ->join('r.line', 'l')
            ->addSelect('l')
            ->join('l.purchaseOrder', 'po')
            ->addSelect('po')
            ->where('l.stockProduct = :stockProduct')
            ->setParameter('stockProduct', $stockProduct)
            ->orderBy('r.receivedAt', 'DESC')
            ->addOrderBy('r.id', 'DESC')
            ->getQuery()
            ->getResult();
    }
}
