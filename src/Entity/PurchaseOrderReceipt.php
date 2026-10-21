<?php

namespace App\Entity;

use App\Repository\PurchaseOrderReceiptRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * One delivery of some quantity of a purchase order line. Permanent — there's no edit or delete;
 * a miscount is fixed with an ordinary stock adjustment. For a stock line it points at the
 * InventoryMovement it created, and records the product's average cost after it for the cost
 * history on the product page.
 */
#[ORM\Entity(repositoryClass: PurchaseOrderReceiptRepository::class)]
class PurchaseOrderReceipt
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: PurchaseOrderLine::class, inversedBy: 'receipts')]
    #[ORM\JoinColumn(nullable: false)]
    private PurchaseOrderLine $line;

    #[ORM\Column]
    private int $qty;

    #[ORM\Column]
    private \DateTimeImmutable $receivedAt;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $receivedBy = null;

    #[ORM\OneToOne(targetEntity: InventoryMovement::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?InventoryMovement $inventoryMovement = null;

    /** Stock lines only: StockProduct.costPrice straight after this receipt was averaged in. */
    #[ORM\Column(type: Types::DECIMAL, precision: 8, scale: 2, nullable: true)]
    private ?string $averageCostAfter = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $note = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->receivedAt = new \DateTimeImmutable();
        $this->createdAt  = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getLine(): PurchaseOrderLine
    {
        return $this->line;
    }

    public function setLine(PurchaseOrderLine $line): static
    {
        $this->line = $line;
        return $this;
    }

    public function getQty(): int
    {
        return $this->qty;
    }

    public function setQty(int $qty): static
    {
        $this->qty = $qty;
        return $this;
    }

    public function getReceivedAt(): \DateTimeImmutable
    {
        return $this->receivedAt;
    }

    public function setReceivedAt(\DateTimeImmutable $receivedAt): static
    {
        $this->receivedAt = $receivedAt;
        return $this;
    }

    public function getReceivedBy(): ?User
    {
        return $this->receivedBy;
    }

    public function setReceivedBy(?User $receivedBy): static
    {
        $this->receivedBy = $receivedBy;
        return $this;
    }

    public function getInventoryMovement(): ?InventoryMovement
    {
        return $this->inventoryMovement;
    }

    public function setInventoryMovement(?InventoryMovement $inventoryMovement): static
    {
        $this->inventoryMovement = $inventoryMovement;
        return $this;
    }

    public function getAverageCostAfter(): ?string
    {
        return $this->averageCostAfter;
    }

    public function setAverageCostAfter(?string $averageCostAfter): static
    {
        $this->averageCostAfter = $averageCostAfter;
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
}
