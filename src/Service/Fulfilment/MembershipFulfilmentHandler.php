<?php

namespace App\Service\Fulfilment;

use App\Entity\Membership;
use App\Entity\MembershipType;
use App\Entity\Product;
use App\Entity\SalesOrderRow;
use Doctrine\ORM\EntityManagerInterface;

/** Selling a membership product takes out a new Membership of the linked type for the beneficiary. Assumes qty 1 — buying more than one membership in a single row isn't a supported flow. */
class MembershipFulfilmentHandler implements FulfilmentHandlerInterface
{
    public function __construct(private readonly EntityManagerInterface $em) {}

    public function supports(string $productType): bool
    {
        return $productType === Product::TYPE_MEMBERSHIP;
    }

    public function fulfil(SalesOrderRow $row): void
    {
        $membershipType = $row->getProduct()->getMembershipProduct()->getMembershipType();

        $membership = new Membership();
        $membership->setUser($row->getEffectiveBeneficiary());
        $membership->setMembershipType($membershipType);
        $membership->setStatus(Membership::STATUS_ACTIVE);
        $membership->setPrice($row->getChargedPrice());
        $membership->setPaidAmount($row->getChargedPrice());
        $membership->setExpiresAt($this->calculateExpiry($membershipType));

        $this->em->persist($membership);
    }

    private function calculateExpiry(MembershipType $membershipType): \DateTimeImmutable
    {
        $now = new \DateTimeImmutable();

        return match ($membershipType->getDuration()) {
            MembershipType::DURATION_DAY => $now->modify('+1 day'),
            MembershipType::DURATION_MONTH => $now->modify('+1 month'),
            MembershipType::DURATION_YEAR => $now->modify('+1 year'),
        };
    }
}
