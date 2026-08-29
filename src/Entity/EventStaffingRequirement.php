<?php

namespace App\Entity;

use App\Repository\EventStaffingRequirementRepository;
use Doctrine\ORM\Mapping as ORM;

/** How many holders of a given certification must be on duty at an event — e.g. "at least 1 Supervisor". */
#[ORM\Entity(repositoryClass: EventStaffingRequirementRepository::class)]
#[ORM\UniqueConstraint(name: 'UNIQ_STAFFING_EVENT_CERT', columns: ['event_id', 'certification_id'])]
class EventStaffingRequirement
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Event::class, inversedBy: 'staffingRequirements')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Event $event;

    #[ORM\ManyToOne(targetEntity: Certification::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Certification $certification;

    #[ORM\Column(options: ['default' => 1])]
    private int $minCount = 1;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getEvent(): Event
    {
        return $this->event;
    }

    public function setEvent(Event $event): static
    {
        $this->event = $event;
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

    public function getMinCount(): int
    {
        return $this->minCount;
    }

    public function setMinCount(int $minCount): static
    {
        $this->minCount = $minCount;
        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
