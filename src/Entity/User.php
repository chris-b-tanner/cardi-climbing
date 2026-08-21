<?php

namespace App\Entity;

use App\Repository\UserRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;

#[ORM\Entity(repositoryClass: UserRepository::class)]
#[ORM\Table(name: '`user`')]
#[UniqueEntity(fields: ['email'], message: 'There is already an account with this email')]
class User implements UserInterface, PasswordAuthenticatedUserInterface
{
    public const ROLE_ADMIN  = 'ROLE_ADMIN';
    public const ROLE_STAFF  = 'ROLE_STAFF';
    public const ROLE_MEMBER = 'ROLE_MEMBER';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 180, unique: true)]
    private ?string $email = null;

    #[ORM\Column]
    private array $roles = [];

    #[ORM\Column]
    private ?string $password = null;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $firstName = null;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $lastName = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(options: ['default' => true])]
    private bool $optIn = true;

    #[ORM\Column(length: 30, nullable: true)]
    private ?string $phone = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $addressLine1 = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $addressLine2 = null;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $town = null;

    #[ORM\Column(length: 20, nullable: true)]
    private ?string $postcode = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $memo = null;

    #[ORM\Column(length: 180, nullable: true)]
    private ?string $email2 = null;

    #[ORM\Column(length: 180, nullable: true)]
    private ?string $email3 = null;

    #[ORM\ManyToMany(targetEntity: Tag::class, inversedBy: 'users')]
    #[ORM\JoinTable(name: 'user_tag')]
    private Collection $tags;

    /** This member's induction/certification records (started/completed), used to gate booking onto restricted events. */
    #[ORM\OneToMany(targetEntity: UserCertification::class, mappedBy: 'user', cascade: ['remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['startedAt' => 'DESC'])]
    private Collection $certifications;

    #[ORM\OneToMany(targetEntity: Note::class, mappedBy: 'user', cascade: ['remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['createdAt' => 'DESC'])]
    private Collection $notes;

    public function __construct()
    {
        $this->createdAt      = new \DateTimeImmutable();
        $this->roles          = [self::ROLE_MEMBER];
        $this->tags           = new ArrayCollection();
        $this->certifications = new ArrayCollection();
        $this->notes          = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getEmail(): ?string
    {
        return $this->email;
    }

    public function setEmail(string $email): static
    {
        $this->email = $email;

        return $this;
    }

    public function getUserIdentifier(): string
    {
        return (string) $this->email;
    }

    public function getRoles(): array
    {
        $roles   = $this->roles;
        $roles[] = 'ROLE_USER';

        return array_unique($roles);
    }

    public function setRoles(array $roles): static
    {
        $this->roles = $roles;

        return $this;
    }

    public function getPassword(): ?string
    {
        return $this->password;
    }

    public function setPassword(string $password): static
    {
        $this->password = $password;

        return $this;
    }

    public function getFirstName(): ?string
    {
        return $this->firstName;
    }

    public function setFirstName(?string $firstName): static
    {
        $this->firstName = $firstName;

        return $this;
    }

    public function getLastName(): ?string
    {
        return $this->lastName;
    }

    public function setLastName(?string $lastName): static
    {
        $this->lastName = $lastName;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function isOptIn(): bool
    {
        return $this->optIn;
    }

    public function setOptIn(bool $optIn): static
    {
        $this->optIn = $optIn;
        return $this;
    }

    public function eraseCredentials(): void {}

    public function getTags(): Collection
    {
        return $this->tags;
    }

    public function hasTag(Tag $tag): bool
    {
        return $this->tags->contains($tag);
    }

    public function addTag(Tag $tag): static
    {
        if (!$this->tags->contains($tag)) {
            $this->tags->add($tag);
        }
        return $this;
    }

    public function removeTag(Tag $tag): static
    {
        $this->tags->removeElement($tag);
        return $this;
    }

    /** This member's induction/certification records, most recently started first. */
    public function getCertifications(): Collection
    {
        return $this->certifications;
    }

    /** Whether this member has a completed (signed-off) induction for the given certification. */
    public function hasCertification(Certification $certification): bool
    {
        foreach ($this->certifications as $record) {
            if ($record->getCertification() === $certification && $record->isComplete()) {
                return true;
            }
        }
        return false;
    }

    public function getPhone(): ?string { return $this->phone; }
    public function setPhone(?string $phone): static { $this->phone = $phone; return $this; }

    public function getAddressLine1(): ?string { return $this->addressLine1; }
    public function setAddressLine1(?string $addressLine1): static { $this->addressLine1 = $addressLine1; return $this; }

    public function getAddressLine2(): ?string { return $this->addressLine2; }
    public function setAddressLine2(?string $addressLine2): static { $this->addressLine2 = $addressLine2; return $this; }

    public function getTown(): ?string { return $this->town; }
    public function setTown(?string $town): static { $this->town = $town; return $this; }

    public function getPostcode(): ?string { return $this->postcode; }
    public function setPostcode(?string $postcode): static { $this->postcode = $postcode; return $this; }

    public function getMemo(): ?string { return $this->memo; }
    public function setMemo(?string $memo): static { $this->memo = $memo; return $this; }

    public function getEmail2(): ?string { return $this->email2; }
    public function setEmail2(?string $email2): static { $this->email2 = $email2; return $this; }

    public function getEmail3(): ?string { return $this->email3; }
    public function setEmail3(?string $email3): static { $this->email3 = $email3; return $this; }

    /** Stores an email in the next available alternate slot. Returns false if all slots are full. */
    public function addAlternateEmail(string $email): bool
    {
        if (!$this->email2) { $this->email2 = $email; return true; }
        if (!$this->email3) { $this->email3 = $email; return true; }
        return false;
    }

    public function getNotes(): Collection { return $this->notes; }
}
