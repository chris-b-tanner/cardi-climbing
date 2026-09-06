<?php

namespace App\Entity;

use App\Repository\InventoryMovementRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/** A single change in stock for a StockProduct — positive for stock coming in, negative for stock going out. */
#[ORM\Entity(repositoryClass: InventoryMovementRepository::class)]
class InventoryMovement
{
    public const REASON_INITIAL    = 'initial';
    public const REASON_PURCHASE   = 'purchase';
    public const REASON_SALE       = 'sale';
    public const REASON_ADJUSTMENT = 'adjustment';
    public const REASON_RETURN     = 'return';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: StockProduct::class, inversedBy: 'inventoryMovements')]
    #[ORM\JoinColumn(name: 'stock_product_id', referencedColumnName: 'product_id', nullable: false, onDelete: 'CASCADE')]
    private StockProduct $stockProduct;

    #[ORM\Column]
    private int $quantityChange;

    /** Set for a sale-driven movement — the row whose fulfilment moved this stock. Null for a manual adjustment, initial stock, etc. */
    #[ORM\ManyToOne(targetEntity: SalesOrderRow::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?SalesOrderRow $salesOrderRow = null;

    /** Net cost price (ex VAT) at the time of this movement — copied from the stock product's cost price when stock is added, so past purchases keep the price paid at the time. */
    #[ORM\Column(type: Types::DECIMAL, precision: 8, scale: 2, nullable: true)]
    private ?string $netPrice = null;

    #[ORM\Column(length: 20)]
    private string $reason;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $note = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $createdBy = null;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getStockProduct(): StockProduct
    {
        return $this->stockProduct;
    }

    public function setStockProduct(StockProduct $stockProduct): static
    {
        $this->stockProduct = $stockProduct;
        return $this;
    }

    public function getQuantityChange(): int
    {
        return $this->quantityChange;
    }

    public function setQuantityChange(int $quantityChange): static
    {
        $this->quantityChange = $quantityChange;
        return $this;
    }

    public function getSalesOrderRow(): ?SalesOrderRow
    {
        return $this->salesOrderRow;
    }

    public function setSalesOrderRow(?SalesOrderRow $salesOrderRow): static
    {
        $this->salesOrderRow = $salesOrderRow;
        return $this;
    }

    public function getNetPrice(): ?string
    {
        return $this->netPrice;
    }

    public function setNetPrice(?string $netPrice): static
    {
        $this->netPrice = $netPrice;
        return $this;
    }

    public function getReason(): string
    {
        return $this->reason;
    }

    public function setReason(string $reason): static
    {
        $this->reason = $reason;
        return $this;
    }

    public function getNote(): ?string
    {
        return $this->note;
    }

    public function setNote(?string $note): static
    {
        $this->note = $note;
        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getCreatedBy(): ?User
    {
        return $this->createdBy;
    }

    public function setCreatedBy(?User $createdBy): static
    {
        $this->createdBy = $createdBy;
        return $this;
    }
}
