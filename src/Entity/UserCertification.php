<?php

namespace App\Entity;

use App\Repository\UserCertificationRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/** A member's induction/certification record — tracks who started and signed it off, and when. */
#[ORM\Entity(repositoryClass: UserCertificationRepository::class)]
class UserCertification
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class, inversedBy: 'certifications')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\ManyToOne(targetEntity: Certification::class, inversedBy: 'userCertifications')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Certification $certification;

    #[ORM\Column]
    private \DateTimeImmutable $startedAt;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $startedBy = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $completedAt = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $completedBy = null;

    /** Base64 PNG data URI of the member's signature, captured at completion. */
    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $signature = null;

    /** Snapshot of which of the certification's declarations the member agreed to. */
    #[ORM\ManyToMany(targetEntity: Declaration::class)]
    #[ORM\JoinTable(name: 'user_certification_declaration')]
    #[ORM\OrderBy(['sortOrder' => 'ASC'])]
    private Collection $agreedDeclarations;

    public function __construct()
    {
        $this->startedAt          = new \DateTimeImmutable();
        $this->agreedDeclarations = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
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

    public function getCertification(): Certification
    {
        return $this->certification;
    }

    public function setCertification(Certification $certification): static
    {
        $this->certification = $certification;
        return $this;
    }

    public function getStartedAt(): \DateTimeImmutable
    {
        return $this->startedAt;
    }

    public function setStartedAt(\DateTimeImmutable $startedAt): static
    {
        $this->startedAt = $startedAt;
        return $this;
    }

    public function getStartedBy(): ?User
    {
        return $this->startedBy;
    }

    public function setStartedBy(?User $startedBy): static
    {
        $this->startedBy = $startedBy;
        return $this;
    }

    public function getCompletedAt(): ?\DateTimeImmutable
    {
        return $this->completedAt;
    }

    public function setCompletedAt(?\DateTimeImmutable $completedAt): static
    {
        $this->completedAt = $completedAt;
        return $this;
    }

    public function getCompletedBy(): ?User
    {
        return $this->completedBy;
    }

    public function setCompletedBy(?User $completedBy): static
    {
        $this->completedBy = $completedBy;
        return $this;
    }

    public function isComplete(): bool
    {
        return $this->completedAt !== null;
    }

    public function getSignature(): ?string
    {
        return $this->signature;
    }

    public function setSignature(?string $signature): static
    {
        $this->signature = $signature;
        return $this;
    }

    public function getAgreedDeclarations(): Collection
    {
        return $this->agreedDeclarations;
    }

    public function addAgreedDeclaration(Declaration $declaration): static
    {
        if (!$this->agreedDeclarations->contains($declaration)) {
            $this->agreedDeclarations->add($declaration);
        }
        return $this;
    }

    public function removeAgreedDeclaration(Declaration $declaration): static
    {
        $this->agreedDeclarations->removeElement($declaration);
        return $this;
    }
}
