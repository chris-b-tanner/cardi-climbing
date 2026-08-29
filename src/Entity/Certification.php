<?php

namespace App\Entity;

use App\Repository\CertificationRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: CertificationRepository::class)]
class Certification
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 100, unique: true)]
    private string $name;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $description = null;

    #[ORM\OneToMany(targetEntity: UserCertification::class, mappedBy: 'certification')]
    private Collection $userCertifications;

    #[ORM\ManyToMany(targetEntity: Event::class, mappedBy: 'restrictions')]
    private Collection $events;

    #[ORM\OneToMany(targetEntity: Declaration::class, mappedBy: 'certification')]
    #[ORM\OrderBy(['sortOrder' => 'ASC'])]
    private Collection $declarations;

    public function __construct()
    {
        $this->userCertifications = new ArrayCollection();
        $this->events             = new ArrayCollection();
        $this->declarations       = new ArrayCollection();
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

    public function getUserCertifications(): Collection
    {
        return $this->userCertifications;
    }

    public function getEvents(): Collection
    {
        return $this->events;
    }

    public function getDeclarations(): Collection
    {
        return $this->declarations;
    }
}
