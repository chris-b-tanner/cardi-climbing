<?php

namespace App\Entity;

use App\Repository\CreditLedgerEntryRepository;
use Doctrine\ORM\Mapping as ORM;

/** A single change in a member's drop-in credit balance — positive when credit is sold to them, negative when redeemed against an event. Mirrors InventoryMovement, but against a member's balance rather than stock units. */
#[ORM\Entity(repositoryClass: CreditLedgerEntryRepository::class)]
class CreditLedgerEntry
{
    public const REASON_PURCHASE   = 'purchase';
    public const REASON_REDEMPTION = 'redemption';
    public const REASON_ADJUSTMENT = 'adjustment';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class, inversedBy: 'creditLedgerEntries')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column]
    private int $creditChange;

    /** Set for a purchase entry — the row whose fulfilment granted this credit. Null for a redemption or manual adjustment. */
    #[ORM\ManyToOne(targetEntity: SalesOrderRow::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?SalesOrderRow $salesOrderRow = null;

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

    public function getUser(): User
    {
        return $this->user;
    }

    public function setUser(User $user): static
    {
        $this->user = $user;
        return $this;
    }

    public function getCreditChange(): int
    {
        return $this->creditChange;
    }

    public function setCreditChange(int $creditChange): static
    {
        $this->creditChange = $creditChange;
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
