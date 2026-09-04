<?php

namespace App\Entity;

use App\Repository\CreditProductRepository;
use Doctrine\ORM\Mapping as ORM;

/** Extension of a Product with productType = credit (e.g. "Access credit (1)") — buying one adds credits to the member's account for event access. */
#[ORM\Entity(repositoryClass: CreditProductRepository::class)]
class CreditProduct
{
    #[ORM\Id]
    #[ORM\OneToOne(targetEntity: Product::class, inversedBy: 'creditProduct')]
    #[ORM\JoinColumn(name: 'product_id', referencedColumnName: 'id', onDelete: 'CASCADE')]
    private Product $product;

    #[ORM\Column]
    private int $creditsGranted;

    public function __construct(Product $product)
    {
        $this->product = $product;
    }

    public function getProduct(): Product
    {
        return $this->product;
    }

    public function getCreditsGranted(): int
    {
        return $this->creditsGranted;
    }

    public function setCreditsGranted(int $creditsGranted): static
    {
        $this->creditsGranted = $creditsGranted;
        return $this;
    }
}
