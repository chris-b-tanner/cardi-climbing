<?php

namespace App\Entity;

use App\Repository\EventRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * A bookable event — either one-off (film nights, competitions, training sessions,
 * parties) or recurring (weekly social night, daily opening slots).
 *
 * Recurring events are stored as a single row rather than one row per occurrence:
 * `date` is the first occurrence, `recurUntil` is the last, and `recurDays` lists
 * which ISO weekdays (1=Monday..7=Sunday) it repeats on. Occurrences are expanded
 * at query time via isValidForDate(), not materialised as separate rows.
 */
#[ORM\Entity(repositoryClass: EventRepository::class)]
class Event
{
    public const STATUS_DRAFT     = 'draft';
    public const STATUS_PUBLISHED = 'published';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private string $title;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $description = null;

    /** Sent in the booking confirmation email — e.g. what to bring, where to park. */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $attendeeInfo = null;

    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    private \DateTimeImmutable $date;

    /** 24-hour "HH:MM" */
    #[ORM\Column(length: 5)]
    private string $timeFrom;

    /** 24-hour "HH:MM" */
    #[ORM\Column(length: 5)]
    private string $timeTo;

    #[ORM\Column(nullable: true)]
    private ?int $maxAttendees = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 8, scale: 2, nullable: true)]
    private ?string $price = null;

    #[ORM\Column(length: 255)]
    private string $location;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $externalUrl = null;

    #[ORM\Column(length: 20)]
    private string $status = self::STATUS_DRAFT;

    #[ORM\Column]
    private bool $isRecurring = false;

    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $recurUntil = null;

    /** Comma-separated ISO weekdays (1=Monday..7=Sunday), e.g. "1,3,5" */
    #[ORM\Column(length: 20, nullable: true)]
    private ?string $recurDays = null;

    /** Members must hold at least one of these certifications to book. Empty = open to all. */
    #[ORM\ManyToMany(targetEntity: Certification::class, inversedBy: 'events')]
    #[ORM\JoinTable(name: 'event_certification')]
    private Collection $restrictions;

    #[ORM\OneToMany(targetEntity: Attendee::class, mappedBy: 'event', cascade: ['remove'], orphanRemoval: true)]
    private Collection $attendees;

    /** Minimum on-duty staffing needed per certification — e.g. "at least 1 Supervisor". */
    #[ORM\OneToMany(targetEntity: EventStaffingRequirement::class, mappedBy: 'event', cascade: ['remove'], orphanRemoval: true)]
    private Collection $staffingRequirements;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $author = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->createdAt            = new \DateTimeImmutable();
        $this->restrictions         = new ArrayCollection();
        $this->attendees            = new ArrayCollection();
        $this->staffingRequirements = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function setTitle(string $title): static
    {
        $this->title = $title;
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

    public function getAttendeeInfo(): ?string
    {
        return $this->attendeeInfo;
    }

    public function setAttendeeInfo(?string $attendeeInfo): static
    {
        $this->attendeeInfo = $attendeeInfo;
        return $this;
    }

    public function getDate(): \DateTimeImmutable
    {
        return $this->date;
    }

    public function setDate(\DateTimeImmutable $date): static
    {
        $this->date = $date;
        return $this;
    }

    public function getTimeFrom(): string
    {
        return $this->timeFrom;
    }

    public function setTimeFrom(string $timeFrom): static
    {
        $this->timeFrom = $timeFrom;
        return $this;
    }

    public function getTimeTo(): string
    {
        return $this->timeTo;
    }

    public function setTimeTo(string $timeTo): static
    {
        $this->timeTo = $timeTo;
        return $this;
    }

    public function getMaxAttendees(): ?int
    {
        return $this->maxAttendees;
    }

    public function setMaxAttendees(?int $maxAttendees): static
    {
        $this->maxAttendees = $maxAttendees;
        return $this;
    }

    public function getPrice(): ?string
    {
        return $this->price;
    }

    public function setPrice(?string $price): static
    {
        $this->price = $price;
        return $this;
    }

    public function getLocation(): string
    {
        return $this->location;
    }

    public function setLocation(string $location): static
    {
        $this->location = $location;
        return $this;
    }

    public function getExternalUrl(): ?string
    {
        return $this->externalUrl;
    }

    public function setExternalUrl(?string $externalUrl): static
    {
        $this->externalUrl = $externalUrl;
        return $this;
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

    public function isPublished(): bool
    {
        return $this->status === self::STATUS_PUBLISHED;
    }

    public function isRecurring(): bool
    {
        return $this->isRecurring;
    }

    public function setIsRecurring(bool $isRecurring): static
    {
        $this->isRecurring = $isRecurring;
        return $this;
    }

    public function getRecurUntil(): ?\DateTimeImmutable
    {
        return $this->recurUntil;
    }

    public function setRecurUntil(?\DateTimeImmutable $recurUntil): static
    {
        $this->recurUntil = $recurUntil;
        return $this;
    }

    /** @return int[] ISO weekdays (1=Monday..7=Sunday) */
    public function getRecurDaysArray(): array
    {
        return $this->recurDays !== null && $this->recurDays !== ''
            ? array_map('intval', explode(',', $this->recurDays))
            : [];
    }

    /** @param int[] $days ISO weekdays (1=Monday..7=Sunday) */
    public function setRecurDaysArray(array $days): static
    {
        $days = array_unique(array_map('intval', $days));
        sort($days);
        $this->recurDays = $days ? implode(',', $days) : null;
        return $this;
    }

    public function getRecurDays(): ?string
    {
        return $this->recurDays;
    }

    public function setRecurDays(?string $recurDays): static
    {
        $this->recurDays = $recurDays;
        return $this;
    }

    /** Whether this event (recurring or not) has an occurrence on the given date. */
    public function isValidForDate(\DateTimeInterface $date): bool
    {
        $date = \DateTimeImmutable::createFromInterface($date)->setTime(0, 0);

        if (!$this->isRecurring) {
            return $date == $this->date;
        }

        if ($date < $this->date) {
            return false;
        }

        if ($this->recurUntil && $date > $this->recurUntil) {
            return false;
        }

        return in_array((int) $date->format('N'), $this->getRecurDaysArray(), true);
    }

    public function getRestrictions(): Collection
    {
        return $this->restrictions;
    }

    public function addRestriction(Certification $certification): static
    {
        if (!$this->restrictions->contains($certification)) {
            $this->restrictions->add($certification);
        }
        return $this;
    }

    public function removeRestriction(Certification $certification): static
    {
        $this->restrictions->removeElement($certification);
        return $this;
    }

    /** Whether the given member holds at least one of this event's required certifications. */
    public function allowsUser(User $user): bool
    {
        if ($this->restrictions->isEmpty()) {
            return true;
        }

        foreach ($this->restrictions as $restriction) {
            if ($user->hasCertification($restriction)) {
                return true;
            }
        }

        return false;
    }

    public function getAttendees(): Collection
    {
        return $this->attendees;
    }

    public function getStaffingRequirements(): Collection
    {
        return $this->staffingRequirements;
    }

    public function getStaffingRequirementFor(Certification $certification): ?EventStaffingRequirement
    {
        foreach ($this->staffingRequirements as $requirement) {
            if ($requirement->getCertification() === $certification) {
                return $requirement;
            }
        }
        return null;
    }

    public function getAuthor(): ?User
    {
        return $this->author;
    }

    public function setAuthor(?User $author): static
    {
        $this->author = $author;
        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
