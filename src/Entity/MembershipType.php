<?php

namespace App\Entity;

use App\Repository\MembershipTypeRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/** An admin-managed kind of membership (e.g. "Adult monthly", "Family annual") that members' individual Memberships are taken out against. */
#[ORM\Entity(repositoryClass: MembershipTypeRepository::class)]
class MembershipType
{
    public const DURATION_DAY   = 'day';
    public const DURATION_MONTH = 'month';
    public const DURATION_YEAR  = 'year';

    public const STATUS_ACTIVE   = 'active';
    public const STATUS_INACTIVE = 'inactive';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 100, unique: true)]
    private string $name;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $description = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 8, scale: 2)]
    private string $price;

    #[ORM\Column(length: 10)]
    private string $duration;

    #[ORM\Column(length: 20, options: ['default' => self::STATUS_ACTIVE])]
    private string $status = self::STATUS_ACTIVE;

    #[ORM\Column(options: ['default' => false])]
    private bool $isFamily = false;

    #[ORM\OneToMany(targetEntity: Membership::class, mappedBy: 'membershipType')]
    private Collection $memberships;

    public function __construct()
    {
        $this->memberships = new ArrayCollection();
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

    public function getPrice(): string
    {
        return $this->price;
    }

    public function setPrice(string $price): static
    {
        $this->price = $price;
        return $this;
    }

    public function getDuration(): string
    {
        return $this->duration;
    }

    public function setDuration(string $duration): static
    {
        $this->duration = $duration;
        return $this;
    }

    public function getDurationLabel(): string
    {
        return match ($this->duration) {
            self::DURATION_DAY => 'Day',
            self::DURATION_MONTH => 'Month',
            self::DURATION_YEAR => 'Year',
            default => $this->duration,
        };
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): static
    {
        $this->status = $status;
        return $this;
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    public function getStatusLabel(): string
    {
        return match ($this->status) {
            self::STATUS_ACTIVE => 'Active',
            self::STATUS_INACTIVE => 'Inactive',
            default => $this->status,
        };
    }

    public function isFamily(): bool
    {
        return $this->isFamily;
    }

    public function setIsFamily(bool $isFamily): static
    {
        $this->isFamily = $isFamily;
        return $this;
    }

    public function getMemberships(): Collection
    {
        return $this->memberships;
    }
}
