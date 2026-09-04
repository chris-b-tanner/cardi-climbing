<?php

namespace App\Service\Fulfilment;

use App\Entity\CreditLedgerEntry;
use App\Entity\Product;
use App\Entity\SalesOrderRow;
use Doctrine\ORM\EntityManagerInterface;

/** Selling a credit product tops up the beneficiary's drop-in credit balance. */
class CreditFulfilmentHandler implements FulfilmentHandlerInterface
{
    public function __construct(private readonly EntityManagerInterface $em) {}

    public function supports(string $productType): bool
    {
        return $productType === Product::TYPE_CREDIT;
    }

    public function fulfil(SalesOrderRow $row): void
    {
        $creditsGranted = $row->getProduct()->getCreditProduct()->getCreditsGranted();

        $entry = new CreditLedgerEntry();
        $entry->setUser($row->getEffectiveBeneficiary());
        $entry->setCreditChange($creditsGranted * $row->getQty());
        $entry->setReason(CreditLedgerEntry::REASON_PURCHASE);
        $entry->setSalesOrderRow($row);

        $this->em->persist($entry);
    }
}
