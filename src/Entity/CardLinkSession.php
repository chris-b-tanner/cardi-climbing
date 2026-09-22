<?php

namespace App\Entity;

use App\Repository\CardLinkSessionRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * One card-station link/verify/lookup attempt — see card-setup.md. Unlike the sentinel-string
 * design this replaced, a row here just holds its final `status` once resolved; nothing needs to
 * be "consumed" by whichever poll reads it first, since resolving (CardService::submitScan()) and
 * reading (CardService::getSessionStatus()) are now two independent, ordinary operations.
 *
 * `user` is nullable specifically for MODE_LOOKUP — link/verify are always about one already-known
 * member (arming from their contact page), but a lookup starts with nothing but a tap: it doesn't
 * know who it's about until the scan resolves, if it resolves to anyone at all.
 */
#[ORM\Entity(repositoryClass: CardLinkSessionRepository::class)]
class CardLinkSession
{
    public const MODE_LINK   = 'link';
    public const MODE_VERIFY = 'verify';
    public const MODE_LOOKUP = 'lookup';

    public const STATUS_PENDING   = 'pending';
    public const STATUS_LINKED    = 'linked';
    public const STATUS_MATCHED   = 'matched';
    public const STATUS_MISMATCH  = 'mismatch';
    public const STATUS_CONFLICT  = 'conflict';
    public const STATUS_FOUND     = 'found';
    public const STATUS_NOT_FOUND = 'not_found';
    public const STATUS_CANCELLED = 'cancelled';
    public const STATUS_EXPIRED   = 'expired';

    /** How long an armed session stays valid before a station's next poll should treat it as gone. */
    public const TTL_SECONDS = 60;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** Null only for MODE_LOOKUP — see class docblock. */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'CASCADE')]
    private ?User $user;

    #[ORM\Column(length: 10)]
    private string $mode;

    #[ORM\Column(length: 20)]
    private string $status = self::STATUS_PENDING;

    #[ORM\Column(length: 32, nullable: true)]
    private ?string $scannedUid = null;

    /**
     * Verify: set when the tapped UID resolves to a DIFFERENT known member — see § Privacy in
     * card-setup.md for why this never reaches the station itself.
     * Lookup: set to whoever the tapped UID resolves to, full stop — the entire point of this mode,
     * so (unlike verify) the admin browser is expected to act on this one (redirect to their
     * contact page), not just display it as an aside.
     */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $matchedUser = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $createdBy = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $expiresAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $resolvedAt = null;

    /** @param ?User $user Null only for MODE_LOOKUP. */
    public function __construct(?User $user, string $mode, ?User $createdBy)
    {
        $this->user = $user;
        $this->mode = $mode;
        $this->createdBy = $createdBy;
        $this->createdAt = new \DateTimeImmutable();
        $this->expiresAt = $this->createdAt->modify('+' . self::TTL_SECONDS . ' seconds');
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUser(): ?User
    {
        return $this->user;
    }

    public function getMode(): string
    {
        return $this->mode;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    public function getScannedUid(): ?string
    {
        return $this->scannedUid;
    }

    public function getMatchedUser(): ?User
    {
        return $this->matchedUser;
    }

    public function getExpiresAt(): \DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function getCreatedBy(): ?User
    {
        return $this->createdBy;
    }

    private function resolve(string $status): void
    {
        $this->status = $status;
        $this->resolvedAt = new \DateTimeImmutable();
    }

    public function markLinked(string $scannedUid): void
    {
        $this->scannedUid = $scannedUid;
        $this->resolve(self::STATUS_LINKED);
    }

    public function markConflict(): void
    {
        $this->resolve(self::STATUS_CONFLICT);
    }

    public function markMatched(): void
    {
        $this->resolve(self::STATUS_MATCHED);
    }

    public function markMismatch(string $scannedUid, ?User $matchedUser): void
    {
        $this->scannedUid = $scannedUid;
        $this->matchedUser = $matchedUser;
        $this->resolve(self::STATUS_MISMATCH);
    }

    public function markFound(string $scannedUid, User $matchedUser): void
    {
        $this->scannedUid = $scannedUid;
        $this->matchedUser = $matchedUser;
        $this->resolve(self::STATUS_FOUND);
    }

    public function markNotFound(string $scannedUid): void
    {
        $this->scannedUid = $scannedUid;
        $this->resolve(self::STATUS_NOT_FOUND);
    }

    public function markCancelled(): void
    {
        $this->resolve(self::STATUS_CANCELLED);
    }
}
