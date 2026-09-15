<?php

namespace App\Entity;

use App\Repository\EmailRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * A record of an email sent (or being drafted to send) via AdminEmailController — whatever its
 * audience (a single member, an event's attendees, a certification's holders, a tag-filtered
 * group, or the whole opted-in membership), it goes through the same draft → sent lifecycle: a
 * draft can be edited freely, and only becomes a real send once "Send" is actually clicked.
 *
 * Every send gets a row here purely as an audit record of who was emailed what and when — see
 * Note::$email for the per-recipient side of that trail.
 */
#[ORM\Entity(repositoryClass: EmailRepository::class)]
class Email
{
    public const STATUS_DRAFT = 'draft';
    public const STATUS_SENT  = 'sent';

    public const AUDIENCE_ALL           = 'all';
    public const AUDIENCE_TAGS          = 'tags';
    public const AUDIENCE_EVENT         = 'event';
    public const AUDIENCE_CERTIFICATION = 'certification';
    public const AUDIENCE_USER          = 'user';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private string $subject = '';

    #[ORM\Column(type: Types::TEXT)]
    private string $body = '';

    #[ORM\Column(options: ['default' => false])]
    private bool $useBlankLayout = false;

    /** Admin-flagged as reusable starting content for the compose screens — subject/body/useBlankLayout only, never the audience (see § Repository/AdminEmailController for how it's offered). */
    #[ORM\Column(options: ['default' => false])]
    private bool $template = false;

    /** all | tags. */
    #[ORM\Column(length: 20)]
    private string $audienceType = self::AUDIENCE_ALL;

    /** e.g. {"tagIds": [1, 2]} for audienceType=tags. Empty/absent for "all". */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $audienceParams = null;

    /** Snapshot for display on the list page, e.g. "All opted-in members" or "Tags: Volunteers, Instructors" — recomputed each time the draft is saved, not live at render time. */
    #[ORM\Column(length: 255)]
    private string $audienceLabel = '';

    #[ORM\Column(length: 20)]
    private string $status = self::STATUS_DRAFT;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $createdBy = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    /** Whoever actually clicked "Send" — may differ from createdBy (one team member drafts, another sends). */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $sentBy = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $sentAt = null;

    #[ORM\Column(nullable: true)]
    private ?int $sentCount = null;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = $this->createdAt;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getSubject(): string
    {
        return $this->subject;
    }

    public function setSubject(string $subject): static
    {
        $this->subject = $subject;
        return $this;
    }

    public function getBody(): string
    {
        return $this->body;
    }

    public function setBody(string $body): static
    {
        $this->body = $body;
        return $this;
    }

    public function isUseBlankLayout(): bool
    {
        return $this->useBlankLayout;
    }

    public function setUseBlankLayout(bool $useBlankLayout): static
    {
        $this->useBlankLayout = $useBlankLayout;
        return $this;
    }

    public function isTemplate(): bool
    {
        return $this->template;
    }

    public function setTemplate(bool $template): static
    {
        $this->template = $template;
        return $this;
    }

    public function getAudienceType(): string
    {
        return $this->audienceType;
    }

    public function setAudienceType(string $audienceType): static
    {
        $this->audienceType = $audienceType;
        return $this;
    }

    public function getAudienceParams(): ?array
    {
        return $this->audienceParams;
    }

    public function setAudienceParams(?array $audienceParams): static
    {
        $this->audienceParams = $audienceParams;
        return $this;
    }

    /** @return int[] */
    public function getTagIds(): array
    {
        return array_map('intval', $this->audienceParams['tagIds'] ?? []);
    }

    public function getAudienceLabel(): string
    {
        return $this->audienceLabel;
    }

    public function setAudienceLabel(string $audienceLabel): static
    {
        $this->audienceLabel = $audienceLabel;
        return $this;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function isDraft(): bool
    {
        return $this->status === self::STATUS_DRAFT;
    }

    public function isSent(): bool
    {
        return $this->status === self::STATUS_SENT;
    }

    /** Draft → sent — the moment sending actually starts. Call setSentCount() once the send loop finishes. */
    public function markSent(User $sender): void
    {
        $this->status  = self::STATUS_SENT;
        $this->sentBy  = $sender;
        $this->sentAt ??= new \DateTimeImmutable();
    }

    public function setSentCount(int $sentCount): static
    {
        $this->sentCount = $sentCount;
        return $this;
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

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function getSentBy(): ?User
    {
        return $this->sentBy;
    }

    public function getSentAt(): ?\DateTimeImmutable
    {
        return $this->sentAt;
    }

    public function getSentCount(): ?int
    {
        return $this->sentCount;
    }
}
