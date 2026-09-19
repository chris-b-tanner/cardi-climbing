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
}
