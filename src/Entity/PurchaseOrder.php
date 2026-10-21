<?php

namespace App\Entity;

use App\Repository\PurchaseOrderRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * An order to one supplier (a User with isSupplier set) for stock and/or non-stock items. Raised by
 * the team, approved by an admin, placed with the supplier, then received line by line — see
 * purchase-orders-spec.md. Totals are calculated from the lines plus the delivery charge, never
 * stored. PurchaseOrderService owns every status change.
 */
#[ORM\Entity(repositoryClass: PurchaseOrderRepository::class)]
#[ORM\Index(columns: ['status'])]
class PurchaseOrder
{
    public const STATUS_DRAFT         = 'draft';
    public const STATUS_SUBMITTED     = 'submitted';
    public const STATUS_APPROVED      = 'approved';
    public const STATUS_ORDERED       = 'ordered';
    public const STATUS_PART_RECEIVED = 'part_received';
    public const STATUS_RECEIVED      = 'received';
    public const STATUS_CANCELLED     = 'cancelled';

    public const STATUS_LABELS = [
        self::STATUS_DRAFT         => 'Draft',
        self::STATUS_SUBMITTED     => 'Awaiting approval',
        self::STATUS_APPROVED      => 'Approved',
        self::STATUS_ORDERED       => 'Ordered',
        self::STATUS_PART_RECEIVED => 'Part received',
        self::STATUS_RECEIVED      => 'Received',
        self::STATUS_CANCELLED     => 'Cancelled',
    ];

    /** Placed with the supplier and still waiting on some or all of the delivery. */
    public const AWAITING_DELIVERY = [self::STATUS_ORDERED, self::STATUS_PART_RECEIVED];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** "PO-0001" — set from the id straight after the first save, so it's null only for that moment. */
    #[ORM\Column(length: 20, unique: true, nullable: true)]
    private ?string $number = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false)]
    private User $supplier;

    #[ORM\Column(length: 20)]
    private string $status = self::STATUS_DRAFT;

    /** Their order or quote number. */
    #[ORM\Column(length: 100, nullable: true)]
    private ?string $supplierReference = null;

    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $expectedAt = null;

    /** Net. Not a line, so it's never received. Reported as spend, not added to stock cost. */
    #[ORM\Column(type: Types::DECIMAL, precision: 8, scale: 2)]
    private string $deliveryCharge = '0.00';

    #[ORM\Column(length: 20)]
    private string $deliveryVatCode = Product::VAT_STANDARD;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $createdBy = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $submittedAt = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $submittedBy = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $approvedAt = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $approvedBy = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $orderedAt = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $orderedBy = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $cancelledAt = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $cancelledBy = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $cancelReason = null;

    /** The pinned "Approval needed" system note while the PO is submitted — completed on approve/reject, and can't be completed by hand. */
    #[ORM\OneToOne(targetEntity: Note::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Note $approvalTask = null;

    /** @var Collection<int, PurchaseOrderLine> */
    #[ORM\OneToMany(targetEntity: PurchaseOrderLine::class, mappedBy: 'purchaseOrder', cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['position' => 'ASC', 'id' => 'ASC'])]
    private Collection $lines;

    /** @var Collection<int, PurchaseOrderInvoice> */
    #[ORM\OneToMany(targetEntity: PurchaseOrderInvoice::class, mappedBy: 'purchaseOrder', cascade: ['remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['uploadedAt' => 'DESC'])]
    private Collection $invoices;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
        $this->lines     = new ArrayCollection();
        $this->invoices  = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getNumber(): ?string
    {
        return $this->number;
    }

    /** Derives the number from the id — call once the PO has been flushed for the first time. */
    public function assignNumber(): void
    {
        if ($this->number === null && $this->id !== null) {
            $this->number = sprintf('PO-%04d', $this->id);
        }
    }

    public function getSupplier(): User
    {
        return $this->supplier;
    }

    public function setSupplier(User $supplier): static
    {
        $this->supplier = $supplier;
        return $this;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): static
    {
        $this->status = $status;
        return $this;
    }

    public function getStatusLabel(): string
    {
        return self::STATUS_LABELS[$this->status] ?? $this->status;
    }

    public function isDraft(): bool
    {
        return $this->status === self::STATUS_DRAFT;
    }

    /** Whether the lines can still change — only while it's a draft. */
    public function isEditable(): bool
    {
        return $this->isDraft();
    }

    /** Placed with the supplier and still waiting on some of the delivery. */
    public function isAwaitingDelivery(): bool
    {
        return in_array($this->status, self::AWAITING_DELIVERY, true);
    }

    /** Ordered, still waiting on the delivery, and past the expected date. */
    public function isOverdue(): bool
    {
        return $this->isAwaitingDelivery()
            && $this->expectedAt !== null
            && $this->expectedAt < new \DateTimeImmutable('today');
    }

    public function hasReceipts(): bool
    {
        foreach ($this->lines as $line) {
            if ($line->getReceipts()->count() > 0) {
                return true;
            }
        }
        return false;
    }

    /** Cancelling is allowed at any status until the first delivery is recorded. */
    public function isCancellable(): bool
    {
        return !in_array($this->status, [self::STATUS_RECEIVED, self::STATUS_CANCELLED], true) && !$this->hasReceipts();
    }

    public function getSupplierReference(): ?string
    {
        return $this->supplierReference;
    }

    public function setSupplierReference(?string $supplierReference): static
    {
        $this->supplierReference = $supplierReference;
        return $this;
    }

    public function getExpectedAt(): ?\DateTimeImmutable
    {
        return $this->expectedAt;
    }

    public function setExpectedAt(?\DateTimeImmutable $expectedAt): static
    {
        $this->expectedAt = $expectedAt;
        return $this;
    }

    public function getDeliveryCharge(): string
    {
        return $this->deliveryCharge;
    }

    public function setDeliveryCharge(string $deliveryCharge): static
    {
        $this->deliveryCharge = $deliveryCharge;
        return $this;
    }

    public function getDeliveryVatCode(): string
    {
        return $this->deliveryVatCode;
    }

    public function setDeliveryVatCode(string $deliveryVatCode): static
    {
        $this->deliveryVatCode = $deliveryVatCode;
        return $this;
    }

    public function getDeliveryVat(): string
    {
        return number_format((float) $this->deliveryCharge * (Product::VAT_RATES[$this->deliveryVatCode] ?? 0), 2, '.', '');
    }

    public function getNetTotal(): string
    {
        $total = (float) $this->deliveryCharge;
        foreach ($this->lines as $line) {
            $total += (float) $line->getNetTotal();
        }
        return number_format($total, 2, '.', '');
    }

    public function getVatTotal(): string
    {
        $total = (float) $this->getDeliveryVat();
        foreach ($this->lines as $line) {
            $total += (float) $line->getVat();
        }
        return number_format($total, 2, '.', '');
    }

    public function getGrossTotal(): string
    {
        return number_format((float) $this->getNetTotal() + (float) $this->getVatTotal(), 2, '.', '');
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

    public function getSubmittedAt(): ?\DateTimeImmutable
    {
        return $this->submittedAt;
    }

    public function getSubmittedBy(): ?User
    {
        return $this->submittedBy;
    }

    public function markSubmitted(User $by): void
    {
        $this->status      = self::STATUS_SUBMITTED;
        $this->submittedAt = new \DateTimeImmutable();
        $this->submittedBy = $by;
    }

    /** Back to draft — pulled back by whoever raised it, or rejected by an admin. Keeps the submission history on its notes rather than here. */
    public function markDraft(): void
    {
        $this->status      = self::STATUS_DRAFT;
        $this->submittedAt = null;
        $this->submittedBy = null;
    }

    public function getApprovedAt(): ?\DateTimeImmutable
    {
        return $this->approvedAt;
    }

    public function getApprovedBy(): ?User
    {
        return $this->approvedBy;
    }

    public function markApproved(User $by): void
    {
        $this->status     = self::STATUS_APPROVED;
        $this->approvedAt = new \DateTimeImmutable();
        $this->approvedBy = $by;
    }

    public function getOrderedAt(): ?\DateTimeImmutable
    {
        return $this->orderedAt;
    }

    public function getOrderedBy(): ?User
    {
        return $this->orderedBy;
    }

    public function markOrdered(User $by): void
    {
        $this->status    = self::STATUS_ORDERED;
        $this->orderedAt = new \DateTimeImmutable();
        $this->orderedBy = $by;
    }

    public function getCancelledAt(): ?\DateTimeImmutable
    {
        return $this->cancelledAt;
    }

    public function getCancelledBy(): ?User
    {
        return $this->cancelledBy;
    }

    public function getCancelReason(): ?string
    {
        return $this->cancelReason;
    }

    public function markCancelled(User $by, string $reason): void
    {
        $this->status       = self::STATUS_CANCELLED;
        $this->cancelledAt  = new \DateTimeImmutable();
        $this->cancelledBy  = $by;
        $this->cancelReason = $reason;
    }

    /** Sets part_received or received from what's been received so far. */
    public function refreshReceivedStatus(): void
    {
        if (!$this->isAwaitingDelivery()) {
            return;
        }

        $anyReceived = false;
        $allReceived = true;
        foreach ($this->lines as $line) {
            if ($line->getQtyReceived() > 0) {
                $anyReceived = true;
            }
            if ($line->getQtyOutstanding() > 0) {
                $allReceived = false;
            }
        }

        if ($allReceived) {
            $this->status = self::STATUS_RECEIVED;
        } elseif ($anyReceived) {
            $this->status = self::STATUS_PART_RECEIVED;
        }
    }

    public function getApprovalTask(): ?Note
    {
        return $this->approvalTask;
    }

    public function setApprovalTask(?Note $approvalTask): static
    {
        $this->approvalTask = $approvalTask;
        return $this;
    }

    /** @return Collection<int, PurchaseOrderLine> */
    public function getLines(): Collection
    {
        return $this->lines;
    }

    public function addLine(PurchaseOrderLine $line): static
    {
        if (!$this->lines->contains($line)) {
            $line->setPurchaseOrder($this);
            $line->setPosition($this->lines->count());
            $this->lines->add($line);
        }
        return $this;
    }

    public function removeLine(PurchaseOrderLine $line): static
    {
        $this->lines->removeElement($line);
        return $this;
    }

    public function findLine(int $lineId): ?PurchaseOrderLine
    {
        foreach ($this->lines as $line) {
            if ($line->getId() === $lineId) {
                return $line;
            }
        }
        return null;
    }

    /** @return Collection<int, PurchaseOrderInvoice> */
    public function getInvoices(): Collection
    {
        return $this->invoices;
    }

    public function findInvoice(int $invoiceId): ?PurchaseOrderInvoice
    {
        foreach ($this->invoices as $invoice) {
            if ($invoice->getId() === $invoiceId) {
                return $invoice;
            }
        }
        return null;
    }

    /** @return PurchaseOrderReceipt[] Every receipt across the lines, newest first. */
    public function getReceipts(): array
    {
        $receipts = [];
        foreach ($this->lines as $line) {
            foreach ($line->getReceipts() as $receipt) {
                $receipts[] = $receipt;
            }
        }
        usort($receipts, static fn (PurchaseOrderReceipt $a, PurchaseOrderReceipt $b) => $b->getReceivedAt() <=> $a->getReceivedAt() ?: $b->getId() <=> $a->getId());
        return $receipts;
    }
}
