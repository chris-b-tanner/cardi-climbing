<?php

namespace App\Entity;

use App\Repository\SalesOrderRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/** A cart of SalesOrderRows bought by a member. Carries the payer/context member; each row can name a different beneficiary. */
#[ORM\Entity(repositoryClass: SalesOrderRepository::class)]
class SalesOrder
{
    public const STATUS_DRAFT     = 'draft';
    public const STATUS_COMPLETE  = 'complete';
    public const STATUS_CANCELLED = 'cancelled';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** The payer/context member. Individual rows may name a different beneficiary. */
    #[ORM\ManyToOne(targetEntity: User::class, inversedBy: 'salesOrders')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column(length: 20)]
    private string $status = self::STATUS_DRAFT;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    /** Staff member who created this order on the member's behalf. Null when created automatically — e.g. from a Stripe webhook. */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $createdBy = null;

    #[ORM\OneToMany(targetEntity: SalesOrderRow::class, mappedBy: 'order', cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['id' => 'ASC'])]
    private Collection $rows;

    /** Payments made against this order. More than one is normal — e.g. a failed attempt followed by a successful retry. */
    #[ORM\OneToMany(targetEntity: Payment::class, mappedBy: 'order')]
    #[ORM\OrderBy(['createdAt' => 'DESC'])]
    private Collection $payments;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
        $this->rows       = new ArrayCollection();
        $this->payments   = new ArrayCollection();
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
        return match ($this->status) {
            self::STATUS_DRAFT => 'Draft',
            self::STATUS_COMPLETE => 'Complete',
            self::STATUS_CANCELLED => 'Cancelled',
            default => $this->status,
        };
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

    public function getRows(): Collection
    {
        return $this->rows;
    }

    public function addRow(SalesOrderRow $row): static
    {
        if (!$this->rows->contains($row)) {
            $this->rows->add($row);
            $row->setOrder($this);
        }
        return $this;
    }

    public function removeRow(SalesOrderRow $row): static
    {
        $this->rows->removeElement($row);
        return $this;
    }

    /** Sum of each row's line total (qty × chargedPrice, inc VAT), most useful once the order is complete. */
    public function getTotal(): string
    {
        $total = 0.0;
        foreach ($this->rows as $row) {
            $total += (float) $row->getLineTotal();
        }
        return number_format($total, 2, '.', '');
    }

    /** Payments made against this order, most recent first. */
    public function getPayments(): Collection
    {
        return $this->payments;
    }
}
