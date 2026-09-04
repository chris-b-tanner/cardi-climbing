<?php

namespace App\Entity;

use App\Repository\MembershipProductRepository;
use Doctrine\ORM\Mapping as ORM;

/** Extension of a Product with productType = membership (e.g. "Adult annual") — buying one creates a Membership of the linked MembershipType. */
#[ORM\Entity(repositoryClass: MembershipProductRepository::class)]
class MembershipProduct
{
    #[ORM\Id]
    #[ORM\OneToOne(targetEntity: Product::class, inversedBy: 'membershipProduct')]
    #[ORM\JoinColumn(name: 'product_id', referencedColumnName: 'id', onDelete: 'CASCADE')]
    private Product $product;

    #[ORM\ManyToOne(targetEntity: MembershipType::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private MembershipType $membershipType;

    public function __construct(Product $product)
    {
        $this->product = $product;
    }

    public function getProduct(): Product
    {
        return $this->product;
    }

    public function getMembershipType(): MembershipType
    {
        return $this->membershipType;
    }

    public function setMembershipType(MembershipType $membershipType): static
    {
        $this->membershipType = $membershipType;
        return $this;
    }
}
