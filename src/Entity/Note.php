<?php

namespace App\Entity;

use App\Repository\NoteRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/** A note attachable to any of five record types (see TYPE_* below), identified by noteableType + noteableId rather than a Doctrine association — there's no single target entity to point a ManyToOne at. */
#[ORM\Entity(repositoryClass: NoteRepository::class)]
#[ORM\Index(name: 'idx_note_email_ref', columns: ['email_ref'])]
class Note
{
    public const TYPE_MEMBER   = 'member';
    public const TYPE_ATTENDEE = 'attendee';
    public const TYPE_EVENT    = 'event';
    public const TYPE_PRODUCT  = 'product';
    public const TYPE_ORDER    = 'order';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 20)]
    private string $noteableType;

    #[ORM\Column]
    private int $noteableId;

    #[ORM\Column(type: Types::TEXT)]
    private string $content;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $addedBy = null;

    /** Set when this note records a bulk email actually being sent to this recipient — traces back to the full Email (subject, body, audience, approval history). Null for every other kind of note. */
    #[ORM\ManyToOne(targetEntity: Email::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Email $email = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(options: ['default' => false])]
    private bool $pinned = false;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $pinnedAt = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $pinnedBy = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $completedAt = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $completedBy = null;

    /** Who's picked this up — the team's shared task list (see AdminActionsController) lets anyone assign a pinned note to anyone else on staff, or to themselves. Independent of pinned/completed status. */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $assignedTo = null;

    /**
     * Set on a note belonging to the ad hoc email conversation ContactQuickEmailMailer/
     * WebhookController::inbound() carry on with a member — both the admin's own outbound quick
     * emails and the member's inbound replies. Null for every other kind of note, bulk-sent
     * ("Emailed: ...", via Note::$email) included. This is the only piece of state that thread
     * needs: NoteRepository::findLatestEmailThreadNote() reads it back to default the next
     * message's subject to "Re: {this}" and to find what to quote beneath it — see
     * ContactQuickEmailMailer's own docblock for the full design.
     */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $emailSubject = null;

    /**
     * Postmark open tracking — see EmailOpenTracking. Set on the note recording an outbound email
     * (a quick email or a bulk "Emailed: ..." note), sent to Postmark as message metadata and
     * handed back on its open webhook (WebhookController::postmarkOpen()), which stamps the two
     * fields below. Null on every other note, and on emails sent before tracking existed.
     */
    #[ORM\Column(length: 32, nullable: true)]
    private ?string $emailRef = null;

    /** When the recipient first opened the email, per Postmark. */
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $emailOpenedAt = null;

    /** Every open Postmark reported, the first included. */
    #[ORM\Column(options: ['default' => 0])]
    private int $emailOpenCount = 0;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getNoteableType(): string
    {
        return $this->noteableType;
    }

    public function getNoteableId(): int
    {
        return $this->noteableId;
    }

    /** Sets noteableType/noteableId from whichever of the five supported entity types this is. */
    public function setNoteable(object $entity): static
    {
        $this->noteableType = match (true) {
            $entity instanceof User => self::TYPE_MEMBER,
            $entity instanceof Attendee => self::TYPE_ATTENDEE,
            $entity instanceof Event => self::TYPE_EVENT,
            $entity instanceof Product => self::TYPE_PRODUCT,
            $entity instanceof SalesOrder => self::TYPE_ORDER,
            default => throw new \InvalidArgumentException(sprintf('%s cannot carry a Note.', $entity::class)),
        };
        $this->noteableId = $entity->getId();
        return $this;
    }

    public function getContent(): string
    {
        return $this->content;
    }

    public function setContent(string $content): static
    {
        $this->content = $content;
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

    public function getEmail(): ?Email
    {
        return $this->email;
    }

    public function setEmail(?Email $email): static
    {
        $this->email = $email;
        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function isPinned(): bool
    {
        return $this->pinned;
    }

    public function getPinnedAt(): ?\DateTimeImmutable
    {
        return $this->pinnedAt;
    }

    public function getPinnedBy(): ?User
    {
        return $this->pinnedBy;
    }

    public function pin(User $by): static
    {
        $this->pinned = true;
        $this->pinnedAt = new \DateTimeImmutable();
        $this->pinnedBy = $by;
        // Re-pinning a previously-completed note reopens it.
        $this->completedAt = null;
        $this->completedBy = null;
        return $this;
    }

    public function unpin(): static
    {
        $this->pinned = false;
        $this->pinnedAt = null;
        $this->pinnedBy = null;
        return $this;
    }

    public function isCompleted(): bool
    {
        return $this->completedAt !== null;
    }

    public function getCompletedAt(): ?\DateTimeImmutable
    {
        return $this->completedAt;
    }

    public function getCompletedBy(): ?User
    {
        return $this->completedBy;
    }

    /** Resolves a pinned note — keeps pinnedAt/pinnedBy as history of when/who flagged it, alongside the new completedAt/completedBy, but drops off the pinned list since it's done. */
    public function complete(User $by): static
    {
        $this->pinned = false;
        $this->completedAt = new \DateTimeImmutable();
        $this->completedBy = $by;
        return $this;
    }

    public function getAssignedTo(): ?User
    {
        return $this->assignedTo;
    }

    public function setAssignedTo(?User $assignedTo): static
    {
        $this->assignedTo = $assignedTo;
        return $this;
    }

    public function getEmailSubject(): ?string
    {
        return $this->emailSubject;
    }

    public function setEmailSubject(?string $emailSubject): static
    {
        $this->emailSubject = $emailSubject;
        return $this;
    }

    public function getEmailRef(): ?string
    {
        return $this->emailRef;
    }

    public function setEmailRef(?string $emailRef): static
    {
        $this->emailRef = $emailRef;
        return $this;
    }

    public function getEmailOpenedAt(): ?\DateTimeImmutable
    {
        return $this->emailOpenedAt;
    }

    public function getEmailOpenCount(): int
    {
        return $this->emailOpenCount;
    }

    /** Records one open reported by Postmark — keeps the earliest as the first-opened time, since webhooks can arrive out of order. */
    public function recordEmailOpen(\DateTimeImmutable $openedAt): static
    {
        if ($this->emailOpenedAt === null || $openedAt < $this->emailOpenedAt) {
            $this->emailOpenedAt = $openedAt;
        }
        $this->emailOpenCount++;
        return $this;
    }
}
