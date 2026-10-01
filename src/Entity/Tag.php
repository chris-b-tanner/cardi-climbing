<?php

namespace App\Entity;

use App\Repository\TagRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: TagRepository::class)]
class Tag
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 100, unique: true)]
    private string $name;

    /** Shown to staff filtering the members list by this tag, so they know what it's for. */
    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $description = null;

    /** Public tags double as newsletter "interest groups" — offered as opt-in checkboxes on the member's own account page (§ AccountController::edit()), not just an internal staff filter. */
    #[ORM\Column(options: ['default' => false])]
    private bool $public = false;

    /** Badge colour as a hex string (e.g. "#0369a1"), chosen by an admin on the tag edit page. Null for a tag never re-saved since this existed — see getEffectiveColor(). */
    #[ORM\Column(length: 7, nullable: true)]
    private ?string $color = null;

    /** Contacts with this tag who haven't been interacted with (User::$lastActivityAt) for this many days are "stale" — pinned to the top of the members list, highlighted, when filtered by this tag. Null = no reminder. */
    #[ORM\Column(nullable: true)]
    private ?int $remindAfterDays = null;

    #[ORM\ManyToMany(targetEntity: User::class, mappedBy: 'tags')]
    private Collection $users;

    public function __construct()
    {
        $this->users = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): static
    {
        $this->name = $name;
        return $this;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): static
    {
        $this->description = $description;
        return $this;
    }

    public function isPublic(): bool
    {
        return $this->public;
    }

    public function setPublic(bool $public): static
    {
        $this->public = $public;
        return $this;
    }

    public function getUsers(): Collection
    {
        return $this->users;
    }

    public function getColor(): ?string
    {
        return $this->color;
    }

    public function setColor(?string $color): static
    {
        $this->color = $color;
        return $this;
    }

    public function getRemindAfterDays(): ?int
    {
        return $this->remindAfterDays;
    }

    public function setRemindAfterDays(?int $remindAfterDays): static
    {
        $this->remindAfterDays = $remindAfterDays;
        return $this;
    }

    /** Whether $user is overdue a contact under this tag's reminder interval — never, if the tag has none. A contact with no recorded interaction counts from when they were created. */
    public function isStale(User $user, ?\DateTimeImmutable $now = null): bool
    {
        if ($this->remindAfterDays === null) {
            return false;
        }

        $lastTouched = $user->getLastActivityAt() ?? $user->getCreatedAt();
        $cutoff      = ($now ?? new \DateTimeImmutable())->modify('-' . $this->remindAfterDays . ' days');

        return $lastTouched < $cutoff;
    }

    /** The colour actually used to render this tag's badge — its own if set, else the original default every tag used before colours existed. */
    public function getEffectiveColor(): string
    {
        return $this->color ?? '#0369a1';
    }
}
