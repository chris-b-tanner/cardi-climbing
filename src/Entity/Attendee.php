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
    /** Not yet wired into any booking flow — reserved for the future waiting-list feature. */
    public const STATUS_WAITING   = 'waiting';

    public const STAFFING_PENDING  = 'pending';
    public const STAFFING_APPROVED = 'approved';
    public const STAFFING_DECLINED = 'declined';

    public const PIN_STATUS_ACTIVE  = 'active';
    public const PIN_STATUS_USED    = 'used';
    public const PIN_STATUS_REVOKED = 'revoked';
    public const PIN_STATUS_EXPIRED = 'expired';

    public const CHECKED_IN_DOOR_PIN = 'door_pin';
    public const CHECKED_IN_MANUAL   = 'manual';

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

    /** Overrides the event's price for this attendee. Null = inherit the event price. Ignored once salesOrderRow is set — see getEffectivePrice(). */
    #[ORM\Column(type: Types::DECIMAL, precision: 8, scale: 2, nullable: true)]
    private ?string $price = null;

    /** Set when this booking was created by fulfilling a SalesOrderRow (an event ticket purchase) — its chargedPrice then drives getEffectivePrice(). Null for admin-added or free bookings. */
    #[ORM\ManyToOne(targetEntity: SalesOrderRow::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?SalesOrderRow $salesOrderRow = null;

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

    /** This booking's 6-digit self-access door PIN — only set for events with isSelfAccess. attendee.id doubles as the door credential_id; see DoorAccessService. */
    #[ORM\Column(length: 6, nullable: true)]
    private ?string $pin = null;

    /** active | used | revoked | expired — null when this booking never got a PIN. */
    #[ORM\Column(length: 20, nullable: true)]
    private ?string $pinStatus = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $checkedInAt = null;

    /** Null = self check-in via door PIN; set = which staff member checked the attendee in manually. */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $checkedInBy = null;

    /** door_pin | manual. Null until checked in. */
    #[ORM\Column(length: 20, nullable: true)]
    private ?string $checkedInMethod = null;

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

    /** This attendee's price: the linked sale's charged price if bought via a SalesOrderRow, otherwise the price override, otherwise free. */
    public function getEffectivePrice(): ?string
    {
        return $this->salesOrderRow?->getChargedPrice() ?? $this->price;
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

    public function getSalesOrderRow(): ?SalesOrderRow
    {
        return $this->salesOrderRow;
    }

    public function setSalesOrderRow(?SalesOrderRow $salesOrderRow): static
    {
        $this->salesOrderRow = $salesOrderRow;
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

    public function getPin(): ?string
    {
        return $this->pin;
    }

    public function setPin(?string $pin): static
    {
        $this->pin = $pin;
        return $this;
    }

    public function getPinStatus(): ?string
    {
        return $this->pinStatus;
    }

    public function setPinStatus(?string $pinStatus): static
    {
        $this->pinStatus = $pinStatus;
        return $this;
    }

    public function isPinActive(): bool
    {
        return $this->pin !== null && $this->pinStatus === self::PIN_STATUS_ACTIVE;
    }

    public function getCheckedInAt(): ?\DateTimeImmutable
    {
        return $this->checkedInAt;
    }

    public function setCheckedInAt(?\DateTimeImmutable $checkedInAt): static
    {
        $this->checkedInAt = $checkedInAt;
        return $this;
    }

    public function getCheckedInBy(): ?User
    {
        return $this->checkedInBy;
    }

    public function setCheckedInBy(?User $checkedInBy): static
    {
        $this->checkedInBy = $checkedInBy;
        return $this;
    }

    public function getCheckedInMethod(): ?string
    {
        return $this->checkedInMethod;
    }

    public function setCheckedInMethod(?string $checkedInMethod): static
    {
        $this->checkedInMethod = $checkedInMethod;
        return $this;
    }

    public function isCheckedIn(): bool
    {
        return $this->checkedInAt !== null;
    }
}
