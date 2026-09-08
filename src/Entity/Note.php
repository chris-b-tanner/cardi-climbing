<?php

namespace App\Entity;

use App\Repository\NoteRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/** A note attachable to any of five record types (see TYPE_* below), identified by noteableType + noteableId rather than a Doctrine association — there's no single target entity to point a ManyToOne at. */
#[ORM\Entity(repositoryClass: NoteRepository::class)]
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
}
