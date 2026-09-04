<?php

namespace App\Service\Fulfilment;

use App\Entity\SalesOrderRow;

/** One implementation per Product::TYPE_* — the downstream effect of a completed order's row actually being sold. */
interface FulfilmentHandlerInterface
{
    public function supports(string $productType): bool;

    public function fulfil(SalesOrderRow $row): void;
}
