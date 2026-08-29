<?php

namespace App\Entity;

use App\Repository\PaymentRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/** A Stripe payment — a donation (no attendee) or a booking payment (linked to an Attendee). Always belongs to a member. */
#[ORM\Entity(repositoryClass: PaymentRepository::class)]
class Payment
{
    public const METHOD_ONLINE   = 'online';
    public const METHOD_TERMINAL = 'terminal';

    public const STATUS_PENDING             = 'pending';
    public const STATUS_SUCCEEDED           = 'succeeded';
    public const STATUS_FAILED              = 'failed';
    public const STATUS_PARTIALLY_REFUNDED  = 'partially_refunded';
    public const STATUS_REFUNDED            = 'refunded';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class, inversedBy: 'payments')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    /** Set for a booking payment; null for a donation. */
    #[ORM\ManyToOne(targetEntity: Attendee::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Attendee $attendee = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 8, scale: 2)]
    private string $amount;

    #[ORM\Column(length: 3, options: ['default' => 'gbp'])]
    private string $currency = 'gbp';

    #[ORM\Column(length: 20)]
    private string $method;

    /** Staff member who triggered a terminal payment on the member's behalf. Null for online self-service. */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $takenBy = null;

    #[ORM\Column(length: 255, nullable: true, unique: true)]
    private ?string $stripePaymentIntentId = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $succeededAt = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $failedAt = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $failureReason = null;

    #[ORM\OneToMany(targetEntity: Refund::class, mappedBy: 'payment', cascade: ['remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['createdAt' => 'DESC'])]
    private Collection $refunds;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
        $this->refunds   = new ArrayCollection();
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

    public function getAttendee(): ?Attendee
    {
        return $this->attendee;
    }

    public function setAttendee(?Attendee $attendee): static
    {
        $this->attendee = $attendee;
        return $this;
    }

    /** Whether this payment is a donation, i.e. not linked to a booking. */
    public function isDonation(): bool
    {
        return $this->attendee === null;
    }

    public function getAmount(): string
    {
        return $this->amount;
    }

    public function setAmount(string $amount): static
    {
        $this->amount = $amount;
        return $this;
    }

    public function getCurrency(): string
    {
        return $this->currency;
    }

    public function setCurrency(string $currency): static
    {
        $this->currency = $currency;
        return $this;
    }

    public function getMethod(): string
    {
        return $this->method;
    }

    public function setMethod(string $method): static
    {
        $this->method = $method;
        return $this;
    }

    public function getTakenBy(): ?User
    {
        return $this->takenBy;
    }

    public function setTakenBy(?User $takenBy): static
    {
        $this->takenBy = $takenBy;
        return $this;
    }

    public function getStripePaymentIntentId(): ?string
    {
        return $this->stripePaymentIntentId;
    }

    public function setStripePaymentIntentId(?string $stripePaymentIntentId): static
    {
        $this->stripePaymentIntentId = $stripePaymentIntentId;
        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getSucceededAt(): ?\DateTimeImmutable
    {
        return $this->succeededAt;
    }

    public function setSucceededAt(?\DateTimeImmutable $succeededAt): static
    {
        $this->succeededAt = $succeededAt;
        return $this;
    }

    public function getFailedAt(): ?\DateTimeImmutable
    {
        return $this->failedAt;
    }

    public function setFailedAt(?\DateTimeImmutable $failedAt): static
    {
        $this->failedAt = $failedAt;
        return $this;
    }

    public function getFailureReason(): ?string
    {
        return $this->failureReason;
    }

    public function setFailureReason(?string $failureReason): static
    {
        $this->failureReason = $failureReason;
        return $this;
    }

    public function getRefunds(): Collection
    {
        return $this->refunds;
    }

    /** Sum of all succeeded refunds against this payment. */
    public function getTotalRefunded(): string
    {
        $total = 0.0;
        foreach ($this->refunds as $refund) {
            if ($refund->getStatus() === Refund::STATUS_SUCCEEDED) {
                $total += (float) $refund->getAmount();
            }
        }
        return number_format($total, 2, '.', '');
    }

    /** How much of this payment is still available to refund. */
    public function getRemainingRefundable(): string
    {
        return number_format((float) $this->amount - (float) $this->getTotalRefunded(), 2, '.', '');
    }

    public function getStatus(): string
    {
        if ($this->failedAt !== null) {
            return self::STATUS_FAILED;
        }
        if ($this->succeededAt === null) {
            return self::STATUS_PENDING;
        }

        $totalRefunded = (float) $this->getTotalRefunded();
        if ($totalRefunded <= 0.0) {
            return self::STATUS_SUCCEEDED;
        }

        return (float) $this->getRemainingRefundable() <= 0.0 ? self::STATUS_REFUNDED : self::STATUS_PARTIALLY_REFUNDED;
    }

    public function getStatusLabel(): string
    {
        return match ($this->getStatus()) {
            self::STATUS_PENDING => 'Pending',
            self::STATUS_SUCCEEDED => 'Succeeded',
            self::STATUS_FAILED => 'Failed',
            self::STATUS_PARTIALLY_REFUNDED => 'Partially refunded',
            self::STATUS_REFUNDED => 'Refunded',
        };
    }
}
