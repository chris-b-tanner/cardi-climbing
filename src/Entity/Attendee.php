<?php

namespace App\Entity;

use App\Repository\AttendeeRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/** A member's booking onto an Event (or, for a recurring Event, one occurrence of it). */
#[ORM\Entity(repositoryClass: AttendeeRepository::class)]
class Attendee
{
    public const STATUS_PENDING   = 'pending';
    public const STATUS_CONFIRMED = 'confirmed';
    public const STATUS_CANCELLED = 'cancelled';

    public const STAFFING_PENDING  = 'pending';
    public const STAFFING_APPROVED = 'approved';
    public const STAFFING_DECLINED = 'declined';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Event::class, inversedBy: 'attendees')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Event $event;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    /** Which occurrence of a recurring event this booking is for. Null for one-off events. */
    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $occurrenceDate = null;

    #[ORM\Column(length: 20)]
    private string $status = self::STATUS_PENDING;

    /** Overrides the event's price for this attendee. Null = inherit the event price. */
    #[ORM\Column(type: Types::DECIMAL, precision: 8, scale: 2, nullable: true)]
    private ?string $price = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 8, scale: 2, options: ['default' => '0.00'])]
    private string $paidAmount = '0.00';

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $addedBy = null;

    /** Set when this booking also serves as a rota slot — which staffing requirement it's fulfilling. Null = a plain booking. */
    #[ORM\ManyToOne(targetEntity: EventStaffingRequirement::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?EventStaffingRequirement $staffingRequirement = null;

    /** pending (self-signed-up, awaiting review) / approved (on duty) / declined. Null when not staffing. */
    #[ORM\Column(length: 20, nullable: true)]
    private ?string $staffingStatus = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getEvent(): Event
    {
        return $this->event;
    }

    public function setEvent(Event $event): static
    {
        $this->event = $event;
        return $this;
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

    public function getOccurrenceDate(): ?\DateTimeImmutable
    {
        return $this->occurrenceDate;
    }

    public function setOccurrenceDate(?\DateTimeImmutable $occurrenceDate): static
    {
        $this->occurrenceDate = $occurrenceDate;
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

    public function isCancelled(): bool
    {
        return $this->status === self::STATUS_CANCELLED;
    }

    /** This attendee's price, or the event's price if not overridden. */
    public function getEffectivePrice(): ?string
    {
        return $this->price ?? $this->event->getPrice();
    }

    public function getPrice(): ?string
    {
        return $this->price;
    }

    public function setPrice(?string $price): static
    {
        $this->price = $price;
        return $this;
    }

    public function getPaidAmount(): string
    {
        return $this->paidAmount;
    }

    public function setPaidAmount(string $paidAmount): static
    {
        $this->paidAmount = $paidAmount;
        return $this;
    }

    public function getAddedBy(): ?User
    {
        return $this->addedBy;
    }

    public function setAddedBy(?User $addedBy): static
    {
        $this->addedBy = $addedBy;
        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getStaffingRequirement(): ?EventStaffingRequirement
    {
        return $this->staffingRequirement;
    }

    public function setStaffingRequirement(?EventStaffingRequirement $staffingRequirement): static
    {
        $this->staffingRequirement = $staffingRequirement;
        return $this;
    }

    public function getStaffingStatus(): ?string
    {
        return $this->staffingStatus;
    }

    public function setStaffingStatus(?string $staffingStatus): static
    {
        $this->staffingStatus = $staffingStatus;
        return $this;
    }

    /** Whether this booking also marks the member as staffing (in any status) rather than just attending. */
    public function isStaffing(): bool
    {
        return $this->staffingRequirement !== null;
    }

    public function isStaffingApproved(): bool
    {
        return $this->staffingStatus === self::STAFFING_APPROVED;
    }

    public function isStaffingPending(): bool
    {
        return $this->staffingStatus === self::STAFFING_PENDING;
    }

    public function getStaffingStatusLabel(): ?string
    {
        return match ($this->staffingStatus) {
            self::STAFFING_PENDING => 'Pending review',
            self::STAFFING_APPROVED => 'On duty',
            self::STAFFING_DECLINED => 'Declined',
            default => null,
        };
    }
}
