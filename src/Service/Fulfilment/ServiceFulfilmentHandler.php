<?php

namespace App\Service\Fulfilment;

use App\Entity\Product;
use App\Entity\SalesOrderRow;

/** Selling a plain service product has no downstream effect beyond the sale itself. */
class ServiceFulfilmentHandler implements FulfilmentHandlerInterface
{
    public function supports(string $productType): bool
    {
        return $productType === Product::TYPE_SERVICE;
    }

    public function fulfil(SalesOrderRow $row): void
    {
        // Intentionally no-op.
    }
}
