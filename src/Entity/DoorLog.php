<?php

namespace App\Entity;

use App\Repository\DoorLogRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * One diagnostic/operational log entry uploaded by a door controller — see
 * door-access-firmware-spec.md § Diagnostic log for the full category/level/reason taxonomy this
 * mirrors. Purely for remote visibility once the device has no serial console attached; unlike
 * AccessEvent, receiving one of these never mutates a booking or attendee row.
 */
#[ORM\Entity(repositoryClass: DoorLogRepository::class)]
class DoorLog
{
    public const LEVEL_INFO  = 'info';
    public const LEVEL_WARN  = 'warn';
    public const LEVEL_ERROR = 'error';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(options: ['default' => 1])]
    private int $doorId = 1;

    /** Client-generated UUID from the device — this row's idempotency key. */
    #[ORM\Column(length: 36, unique: true)]
    private string $logId;

    /** info | warn | error. */
    #[ORM\Column(length: 10)]
    private string $level;

    /** boot | ota | auth | sync | hardware | config. */
    #[ORM\Column(length: 20)]
    private string $category;

    /** Stable machine-readable slug, e.g. "door_propped" — what anything automated matches on, never `message`. */
    #[ORM\Column(length: 30)]
    private string $reason;

    #[ORM\Column(length: 255)]
    private string $message;

    /** Category-dependent extra fields, e.g. {from_version, to_version} for an `ota` entry. */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $context = null;

    /** When the device says this happened. */
    #[ORM\Column]
    private \DateTimeImmutable $timestamp;

    /** When the server actually received it — can lag well behind {timestamp} after a retried/queued upload. */
    #[ORM\Column]
    private \DateTimeImmutable $receivedAt;

    /** Set only when this specific entry actually triggered an alert email — see § Server-side alerting. Null for the great majority of rows, and for one that was alert-worthy but suppressed by the per-door cooldown. */
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $alertSentAt = null;

    public function __construct(string $logId, string $level, string $category, string $reason, string $message, \DateTimeImmutable $timestamp, int $doorId = 1)
    {
        $this->logId      = $logId;
        $this->level      = $level;
        $this->category   = $category;
        $this->reason     = $reason;
        $this->message    = $message;
        $this->timestamp  = $timestamp;
        $this->doorId     = $doorId;
        $this->receivedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getDoorId(): int
    {
        return $this->doorId;
    }

    public function getLogId(): string
    {
        return $this->logId;
    }

    public function getLevel(): string
    {
        return $this->level;
    }

    public function getCategory(): string
    {
        return $this->category;
    }

    public function getReason(): string
    {
        return $this->reason;
    }

    public function getMessage(): string
    {
        return $this->message;
    }

    public function getContext(): ?array
    {
        return $this->context;
    }

    public function setContext(?array $context): static
    {
        $this->context = $context;
        return $this;
    }

    public function getTimestamp(): \DateTimeImmutable
    {
        return $this->timestamp;
    }

    public function getReceivedAt(): \DateTimeImmutable
    {
        return $this->receivedAt;
    }

    public function getAlertSentAt(): ?\DateTimeImmutable
    {
        return $this->alertSentAt;
    }

    public function markAlertSent(): void
    {
        $this->alertSentAt = new \DateTimeImmutable();
    }

    /** Whether this entry's category/level/reason is one we page a human for immediately — an explicit allowlist, not "every error" (see § Server-side alerting). */
    public function isAlertWorthy(): bool
    {
        return $this->category === 'hardware'
            && $this->level === self::LEVEL_ERROR
            && in_array($this->reason, ['door_propped', 'door_unexpected_open'], true);
    }
}
