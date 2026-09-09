<?php

namespace App\Entity;

use App\Repository\MagicLinkRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * A password-free sign-in link — e.g. the certification invite email, so a contact who has never
 * set a password (many are created by an admin without one) can still get straight into their
 * account and the exact page the email is about.
 *
 * Reusable until it expires rather than single-use: it lives only in the recipient's private
 * inbox, so there's little security to gain from also invalidating it after one click, and a
 * "magic link" that stops working the second time is a common source of confusion.
 *
 * The token embedded in the URL is `selector . verifier` (see MagicLinkService): the selector is
 * stored as-is for a cheap, safe lookup, while only a hash of the verifier is ever stored — the
 * same selector/hashed-verifier shape used by password reset tokens.
 */
#[ORM\Entity(repositoryClass: MagicLinkRepository::class)]
class MagicLink
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 32, unique: true)]
    private string $selector;

    /** sha256 of the verifier half of the token — the raw verifier itself is never stored. */
    #[ORM\Column(length: 64)]
    private string $hashedVerifier;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    /** Local path to send the user to once logged in — never a full URL (see MagicLinkService::generate()). */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $redirectPath = null;

    #[ORM\Column]
    private \DateTimeImmutable $expiresAt;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $lastUsedAt = null;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getSelector(): string
    {
        return $this->selector;
    }

    public function setSelector(string $selector): static
    {
        $this->selector = $selector;
        return $this;
    }

    public function getHashedVerifier(): string
    {
        return $this->hashedVerifier;
    }

    public function setHashedVerifier(string $hashedVerifier): static
    {
        $this->hashedVerifier = $hashedVerifier;
        return $this;
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

    public function getRedirectPath(): ?string
    {
        return $this->redirectPath;
    }

    public function setRedirectPath(?string $redirectPath): static
    {
        $this->redirectPath = $redirectPath;
        return $this;
    }

    public function getExpiresAt(): \DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function setExpiresAt(\DateTimeImmutable $expiresAt): static
    {
        $this->expiresAt = $expiresAt;
        return $this;
    }

    public function isExpired(): bool
    {
        return $this->expiresAt < new \DateTimeImmutable();
    }

    public function getLastUsedAt(): ?\DateTimeImmutable
    {
        return $this->lastUsedAt;
    }

    public function setLastUsedAt(?\DateTimeImmutable $lastUsedAt): static
    {
        $this->lastUsedAt = $lastUsedAt;
        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
