<?php

namespace App\Entity;

use App\Repository\AccessEventRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * One row per door interaction, keyed on the device's own client-generated event_id — updated in
 * place as its stage advances, not appended. See door-access-spec.md § Access event log for why
 * this exists as its own entity rather than attendee columns: a keyholder's key-only entry has no
 * booking to attach to, and an unexpected open has no PIN at all.
 */
#[ORM\Entity(repositoryClass: AccessEventRepository::class)]
class AccessEvent
{
    public const TYPE_ATTENDEE_ACCESS  = 'attendee_access';
    public const TYPE_KEYHOLDER_ACCESS = 'keyholder_access';
    public const TYPE_ACCESS_DENIED    = 'access_denied';
    public const TYPE_UNEXPECTED_OPEN  = 'unexpected_open';

    public const STAGE_AUTHORIZED  = 'authorized';
    public const STAGE_DOOR_OPEN   = 'door_open';
    public const STAGE_DOOR_CLOSED = 'door_closed';

    /** Rank order for the upsert-never-regress rule — a stage only ever advances. */
    private const STAGE_RANK = [
        self::STAGE_AUTHORIZED  => 1,
        self::STAGE_DOOR_OPEN   => 2,
        self::STAGE_DOOR_CLOSED => 3,
    ];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(options: ['default' => 1])]
    private int $doorId = 1;

    /** Client-generated UUID from the device — this row's idempotency key. */
    #[ORM\Column(length: 36, unique: true)]
    private string $eventId;

    #[ORM\Column(length: 20)]
    private string $type;

    #[ORM\ManyToOne(targetEntity: Attendee::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Attendee $attendee = null;

    /** Set for keyholder_access — which keyholder's PIN was used. */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $keyholderUser = null;

    /** Raw NFC UID that produced this event — set on attendee_access/access_denied whenever the entry reader (not the keypad) produced it. Null for a PIN-triggered event. */
    #[ORM\Column(length: 32, nullable: true)]
    private ?string $cardUid = null;

    /** The member {cardUid} resolves to, if any — set even when there's no valid attendee/booking (attendee stays null), so a denied tap from a registered card still identifies who tried. Null for an unregistered card or a PIN-triggered event. */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $cardUser = null;

    /** authorized | door_open | door_closed — null for access_denied. */
    #[ORM\Column(length: 20, nullable: true)]
    private ?string $stage = null;

    /** expired | not_found | already_used — access_denied only. */
    #[ORM\Column(length: 20, nullable: true)]
    private ?string $deniedReason = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $authorizedAt = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $doorOpenAt = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $doorClosedAt = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    public function __construct(string $eventId, string $type, int $doorId = 1)
    {
        $this->eventId    = $eventId;
        $this->type       = $type;
        $this->doorId     = $doorId;
        $this->createdAt  = new \DateTimeImmutable();
        $this->updatedAt  = $this->createdAt;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getDoorId(): int
    {
        return $this->doorId;
    }

    public function getEventId(): string
    {
        return $this->eventId;
    }

    public function getType(): string
    {
        return $this->type;
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

    public function getKeyholderUser(): ?User
    {
        return $this->keyholderUser;
    }

    public function setKeyholderUser(?User $keyholderUser): static
    {
        $this->keyholderUser = $keyholderUser;
        return $this;
    }

    public function getCardUid(): ?string
    {
        return $this->cardUid;
    }

    public function getCardUser(): ?User
    {
        return $this->cardUser;
    }

    /** Records which card (and, if it resolves to one, which member) produced this event — see UserRepository::findOneByCardUid(). A no-op for a PIN-triggered event, which never calls this. */
    public function setCard(string $cardUid, ?User $cardUser): static
    {
        $this->cardUid = $cardUid;
        $this->cardUser = $cardUser;
        return $this;
    }

    public function getStage(): ?string
    {
        return $this->stage;
    }

    public function getDeniedReason(): ?string
    {
        return $this->deniedReason;
    }

    public function setDeniedReason(?string $deniedReason): static
    {
        $this->deniedReason = $deniedReason;
        return $this;
    }

    public function getAuthorizedAt(): ?\DateTimeImmutable
    {
        return $this->authorizedAt;
    }

    public function getDoorOpenAt(): ?\DateTimeImmutable
    {
        return $this->doorOpenAt;
    }

    public function getDoorClosedAt(): ?\DateTimeImmutable
    {
        return $this->doorClosedAt;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    /**
     * Applies {stage} and its timestamp if — and only if — it's later than (or equal to, for a
     * harmless retry) whatever stage this row is already at. A delayed/reordered retry of an old
     * stage arriving after a newer one has already been applied is a no-op, not a regression —
     * see door-access-spec.md's events endpoint.
     */
    public function advanceStage(string $stage, \DateTimeImmutable $timestamp): bool
    {
        $incomingRank = self::STAGE_RANK[$stage] ?? null;
        $currentRank  = self::STAGE_RANK[$this->stage] ?? 0;

        if ($incomingRank === null || $incomingRank < $currentRank) {
            return false;
        }

        $this->stage = $stage;
        $this->updatedAt = new \DateTimeImmutable();

        match ($stage) {
            self::STAGE_AUTHORIZED  => $this->authorizedAt ??= $timestamp,
            self::STAGE_DOOR_OPEN   => $this->doorOpenAt ??= $timestamp,
            self::STAGE_DOOR_CLOSED => $this->doorClosedAt ??= $timestamp,
            default => null,
        };

        return true;
    }

    /** Records when an access_denied row occurred — deliberately does NOT touch `stage`, which stays null for this type. Reuses the `authorizedAt` column as this type's one timestamp rather than adding a dedicated column for a single-stage event. */
    public function recordDeniedAt(\DateTimeImmutable $timestamp): void
    {
        $this->authorizedAt ??= $timestamp;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function isStuckAtDoorOpen(): bool
    {
        return $this->stage === self::STAGE_DOOR_OPEN;
    }
}
