<?php

namespace App\Entity;

use App\Repository\AccessCardRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * A physical NFC membership card's identity and lifecycle — see card-setup.md § "Why two real
 * tables, not a field on `user`". Rows are never deleted or overwritten in place: locking,
 * unlocking, and replacing a card all just update status/timestamps here (or, for a replacement,
 * insert a new row and mark this one `replaced`) — that history is the audit trail.
 */
#[ORM\Entity(repositoryClass: AccessCardRepository::class)]
class AccessCard
{
    public const STATUS_ACTIVE   = 'active';
    public const STATUS_LOCKED   = 'locked';
    public const STATUS_REPLACED = 'replaced';
    public const STATUS_REMOVED  = 'removed';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class, inversedBy: 'accessCards')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    /** Uppercase hex, no separators — the reader's native UID. Unique forever, regardless of status. */
    #[ORM\Column(length: 32, unique: true)]
    private string $uid;

    #[ORM\Column(length: 20)]
    private string $status = self::STATUS_ACTIVE;

    #[ORM\Column]
    private \DateTimeImmutable $deployedAt;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $deployedBy = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $lockedAt = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $lockedBy = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $unlockedAt = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $unlockedBy = null;

    /** When this row stopped being current — set for STATUS_REPLACED (superseded by a newer card) and STATUS_REMOVED (no replacement) alike. Who did it and why lives on the Note this same action writes (see CardService), not a dedicated column here. */
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $replacedAt = null;

    /**
     * Standing door access with no attendee booking required at all — see door-access-spec.md §
     * All-hours cards. Deliberately just a bare flag, unlike lock/unlock: who granted/revoked it
     * and when lives on the Note CardService writes for each change, not dedicated columns here —
     * this is a stronger grant than locking, but no more of an audit-worthy *lifecycle state* than
     * any other admin action already covered by the Note trail. Only takes effect while the card
     * is also STATUS_ACTIVE — a locked card is never all-hours regardless of this flag.
     */
    #[ORM\Column(options: ['default' => false])]
    private bool $allHoursAccess = false;

    public function __construct(User $user, string $uid, ?User $deployedBy)
    {
        $this->user = $user;
        $this->uid = $uid;
        $this->deployedAt = new \DateTimeImmutable();
        $this->deployedBy = $deployedBy;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getUid(): string
    {
        return $this->uid;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    public function isLocked(): bool
    {
        return $this->status === self::STATUS_LOCKED;
    }

    public function getDeployedAt(): \DateTimeImmutable
    {
        return $this->deployedAt;
    }

    public function getDeployedBy(): ?User
    {
        return $this->deployedBy;
    }

    public function getLockedAt(): ?\DateTimeImmutable
    {
        return $this->lockedAt;
    }

    public function getLockedBy(): ?User
    {
        return $this->lockedBy;
    }

    public function getUnlockedAt(): ?\DateTimeImmutable
    {
        return $this->unlockedAt;
    }

    public function getUnlockedBy(): ?User
    {
        return $this->unlockedBy;
    }

    /** Set for STATUS_REPLACED and STATUS_REMOVED alike — see the field's own docblock. */
    public function getReplacedAt(): ?\DateTimeImmutable
    {
        return $this->replacedAt;
    }

    /** @throws \LogicException if not currently active */
    public function lock(User $by): void
    {
        if (!$this->isActive()) {
            throw new \LogicException('Only an active card can be locked.');
        }

        $this->status = self::STATUS_LOCKED;
        $this->lockedAt = new \DateTimeImmutable();
        $this->lockedBy = $by;
    }

    /** @throws \LogicException if not currently locked */
    public function unlock(User $by): void
    {
        if (!$this->isLocked()) {
            throw new \LogicException('Only a locked card can be unlocked.');
        }

        $this->status = self::STATUS_ACTIVE;
        $this->unlockedAt = new \DateTimeImmutable();
        $this->unlockedBy = $by;
    }

    /** @throws \LogicException if not currently active or locked */
    public function remove(): void
    {
        if ($this->isActive() === false && $this->isLocked() === false) {
            throw new \LogicException('Only an active or locked card can be removed.');
        }

        $this->status = self::STATUS_REMOVED;
        $this->replacedAt = new \DateTimeImmutable();
    }

    /** Superseded by a newer card for the same member — see CardService::link(). */
    public function markReplaced(): void
    {
        $this->status = self::STATUS_REPLACED;
        $this->replacedAt = new \DateTimeImmutable();
    }

    public function isAllHours(): bool
    {
        return $this->allHoursAccess;
    }

    public function grantAllHours(): void
    {
        $this->allHoursAccess = true;
    }

    public function revokeAllHours(): void
    {
        $this->allHoursAccess = false;
    }
}
