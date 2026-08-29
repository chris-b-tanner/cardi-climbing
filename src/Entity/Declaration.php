<?php

namespace App\Entity;

use App\Repository\DeclarationRepository;
use Doctrine\ORM\Mapping as ORM;

/** A checkbox statement a member must agree to as part of completing a certification form. Belongs to exactly one certification. */
#[ORM\Entity(repositoryClass: DeclarationRepository::class)]
class Declaration
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Certification::class, inversedBy: 'declarations')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Certification $certification;

    #[ORM\Column(type: 'text')]
    private string $text;

    #[ORM\Column]
    private int $sortOrder = 0;

    public function getId(): ?int
    {
        return $this->id;
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

    public function getText(): string
    {
        return $this->text;
    }

    public function setText(string $text): static
    {
        $this->text = $text;
        return $this;
    }

    public function getSortOrder(): int
    {
        return $this->sortOrder;
    }

    public function setSortOrder(int $sortOrder): static
    {
        $this->sortOrder = $sortOrder;
        return $this;
    }
}
