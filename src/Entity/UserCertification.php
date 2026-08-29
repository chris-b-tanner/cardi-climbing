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
    public const STATUS_IN_PROGRESS     = 'in_progress';
    public const STATUS_PENDING_APPROVAL = 'pending_approval';
    public const STATUS_COMPLETED        = 'completed';
    public const STATUS_CANCELLED        = 'cancelled';

    /** Fixed e-signature consent shown above the signature box on every certification — not a stored Declaration. */
    public const SIGNATURE_DECLARATION_TEXT = 'By applying my electronic signature below, I confirm that I have read, understood, and agree to be legally bound by the terms and conditions of this agreement. I acknowledge that my electronic signature constitutes the legal equivalent of my handwritten signature.';

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

    /** Admin sign-off — the final step that turns a member-submitted record into a held certification. */
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $approvedAt = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $approvedBy = null;

    /** Set to hide/void a record — e.g. a bad actor or an expired certification — without deleting its history. */
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $cancelledAt = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $cancelledBy = null;

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

    /** Whether the member has submitted their declarations and signature (may still be awaiting approval). */
    public function isSubmitted(): bool
    {
        return $this->completedAt !== null;
    }

    public function getApprovedAt(): ?\DateTimeImmutable
    {
        return $this->approvedAt;
    }

    public function setApprovedAt(?\DateTimeImmutable $approvedAt): static
    {
        $this->approvedAt = $approvedAt;
        return $this;
    }

    public function getApprovedBy(): ?User
    {
        return $this->approvedBy;
    }

    public function setApprovedBy(?User $approvedBy): static
    {
        $this->approvedBy = $approvedBy;
        return $this;
    }

    public function isApproved(): bool
    {
        return $this->approvedAt !== null;
    }

    public function getCancelledAt(): ?\DateTimeImmutable
    {
        return $this->cancelledAt;
    }

    public function setCancelledAt(?\DateTimeImmutable $cancelledAt): static
    {
        $this->cancelledAt = $cancelledAt;
        return $this;
    }

    public function getCancelledBy(): ?User
    {
        return $this->cancelledBy;
    }

    public function setCancelledBy(?User $cancelledBy): static
    {
        $this->cancelledBy = $cancelledBy;
        return $this;
    }

    public function isCancelled(): bool
    {
        return $this->cancelledAt !== null;
    }

    /** Whether this record currently counts as the member holding the certification. */
    public function isHeld(): bool
    {
        return $this->approvedAt !== null && $this->cancelledAt === null;
    }

    public function getStatus(): string
    {
        if ($this->cancelledAt !== null) {
            return self::STATUS_CANCELLED;
        }
        if ($this->approvedAt !== null) {
            return self::STATUS_COMPLETED;
        }
        if ($this->completedAt !== null) {
            return self::STATUS_PENDING_APPROVAL;
        }
        return self::STATUS_IN_PROGRESS;
    }

    public function getStatusLabel(): string
    {
        return match ($this->getStatus()) {
            self::STATUS_IN_PROGRESS => 'In progress',
            self::STATUS_PENDING_APPROVAL => 'Pending approval',
            self::STATUS_COMPLETED => 'Completed',
            self::STATUS_CANCELLED => 'Cancelled',
        };
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
