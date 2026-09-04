<?php

namespace App\Entity;

use App\Repository\StockProductRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/** Extension of a Product with productType = stock (e.g. climbing shoes) — stock is tracked via InventoryMovement. */
#[ORM\Entity(repositoryClass: StockProductRepository::class)]
class StockProduct
{
    #[ORM\Id]
    #[ORM\OneToOne(targetEntity: Product::class, inversedBy: 'stockProduct')]
    #[ORM\JoinColumn(name: 'product_id', referencedColumnName: 'id', onDelete: 'CASCADE')]
    private Product $product;

    #[ORM\Column(type: Types::DECIMAL, precision: 8, scale: 2)]
    private string $costPrice;

    #[ORM\Column(length: 64, unique: true)]
    private string $sku;

    #[ORM\OneToMany(targetEntity: InventoryMovement::class, mappedBy: 'stockProduct', cascade: ['remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['createdAt' => 'DESC'])]
    private Collection $inventoryMovements;

    public function __construct(Product $product)
    {
        $this->product            = $product;
        $this->inventoryMovements = new ArrayCollection();
    }

    public function getProduct(): Product
    {
        return $this->product;
    }

    public function getCostPrice(): string
    {
        return $this->costPrice;
    }

    public function setCostPrice(string $costPrice): static
    {
        $this->costPrice = $costPrice;
        return $this;
    }

    public function getSku(): string
    {
        return $this->sku;
    }

    public function setSku(string $sku): static
    {
        $this->sku = $sku;
        return $this;
    }

    public function getInventoryMovements(): Collection
    {
        return $this->inventoryMovements;
    }

    /** Current stock on hand — the sum of every recorded movement. */
    public function getCurrentStock(): int
    {
        $total = 0;
        foreach ($this->inventoryMovements as $movement) {
            $total += $movement->getQuantityChange();
        }
        return $total;
    }

    /** Value of stock on hand, at net (ex VAT) cost — built from each movement's own net price rather than the current cost price, so past purchases keep the price paid at the time. */
    public function getAssetValue(): string
    {
        $total = 0.0;
        foreach ($this->inventoryMovements as $movement) {
            if ($movement->getNetPrice() !== null) {
                $total += $movement->getQuantityChange() * (float) $movement->getNetPrice();
            }
        }
        return number_format($total, 2, '.', '');
    }
}
