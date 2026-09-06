<?php

namespace App\Service\Fulfilment;

use App\Entity\InventoryMovement;
use App\Entity\Product;
use App\Entity\SalesOrderRow;
use Doctrine\ORM\EntityManagerInterface;

/** Selling a stock product takes the quantity sold off the shelf. */
class StockFulfilmentHandler implements FulfilmentHandlerInterface
{
    public function __construct(private readonly EntityManagerInterface $em) {}

    public function supports(string $productType): bool
    {
        return $productType === Product::TYPE_STOCK;
    }

    public function fulfil(SalesOrderRow $row): void
    {
        $stockProduct = $row->getProduct()->getStockProduct();

        $movement = new InventoryMovement();
        $movement->setStockProduct($stockProduct);
        $movement->setQuantityChange(-$row->getQty());
        $movement->setNetPrice($stockProduct->getCostPrice());
        $movement->setReason(InventoryMovement::REASON_SALE);
        $movement->setSalesOrderRow($row);

        $this->em->persist($movement);
    }
}
