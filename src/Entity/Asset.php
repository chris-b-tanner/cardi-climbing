<?php

namespace App\Entity;

use App\Repository\AssetRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Something the centre owns and uses but doesn't sell — holds, auto-belays, hire kit, tools, the
 * building itself. Either one individual item (quantity 1, usually with a serial number) or a batch
 * of identical things counted together (quantity > 1). Sellable stock is StockProduct, not this.
 * Values are net of VAT and are totals for the whole entry, not per unit. History lives in Notes
 * (Note::TYPE_ASSET), written by AssetHistory whenever something significant changes.
 */
#[ORM\Entity(repositoryClass: AssetRepository::class)]
#[ORM\Index(columns: ['status'])]
#[ORM\Index(columns: ['category'])]
class Asset
{
    public const CATEGORY_HOLDS_VOLUMES      = 'holds_volumes';
    public const CATEGORY_WALL_EQUIPMENT     = 'wall_equipment';
    public const CATEGORY_HIRE_KIT           = 'hire_kit';
    public const CATEGORY_SETTING_TOOLS      = 'setting_tools';
    public const CATEGORY_TOOLS_EQUIPMENT    = 'tools_equipment';
    public const CATEGORY_FURNITURE_FITTINGS = 'furniture_fittings';
    public const CATEGORY_IT_ELECTRONICS     = 'it_electronics';
    public const CATEGORY_BUILDING           = 'building';
    public const CATEGORY_FIRE_SAFETY        = 'fire_safety';
    public const CATEGORY_ELECTRICAL         = 'electrical';
    public const CATEGORY_FIRST_AID          = 'first_aid';
    public const CATEGORY_OTHER              = 'other';

    public const CATEGORY_LABELS = [
        self::CATEGORY_HOLDS_VOLUMES      => 'Holds & volumes',
        self::CATEGORY_WALL_EQUIPMENT     => 'Wall equipment',
        self::CATEGORY_HIRE_KIT           => 'Hire kit',
        self::CATEGORY_SETTING_TOOLS      => 'Setting tools',
        self::CATEGORY_TOOLS_EQUIPMENT    => 'Tools & equipment',
        self::CATEGORY_FURNITURE_FITTINGS => 'Furniture & fittings',
        self::CATEGORY_IT_ELECTRONICS     => 'IT & electronics',
        self::CATEGORY_BUILDING           => 'Building',
        self::CATEGORY_FIRE_SAFETY        => 'Fire safety',
        self::CATEGORY_ELECTRICAL         => 'Electrical',
        self::CATEGORY_FIRST_AID          => 'First aid',
        self::CATEGORY_OTHER              => 'Other',
    ];

    public const STATUS_IN_USE         = 'in_use';
    public const STATUS_IN_STORAGE     = 'in_storage';
    public const STATUS_OUT_OF_SERVICE = 'out_of_service';
    public const STATUS_DISPOSED       = 'disposed';

    public const STATUS_LABELS = [
        self::STATUS_IN_USE         => 'In use',
        self::STATUS_IN_STORAGE     => 'In storage',
        self::STATUS_OUT_OF_SERVICE => 'Out of service',
        self::STATUS_DISPOSED       => 'Disposed',
    ];

    public const ACQUISITION_PURCHASED = 'purchased';
    public const ACQUISITION_DONATED   = 'donated';
    public const ACQUISITION_OTHER     = 'other';

    public const ACQUISITION_LABELS = [
        self::ACQUISITION_PURCHASED => 'Purchased',
        self::ACQUISITION_DONATED   => 'Donated',
        self::ACQUISITION_OTHER     => 'Other',
    ];

    public const DISPOSAL_WORN_OUT    = 'worn_out';
    public const DISPOSAL_BROKEN      = 'broken';
    public const DISPOSAL_LOST_STOLEN = 'lost_stolen';
    public const DISPOSAL_SOLD        = 'sold';
    public const DISPOSAL_GIVEN_AWAY  = 'given_away';
    public const DISPOSAL_OTHER       = 'other';

    public const DISPOSAL_LABELS = [
        self::DISPOSAL_WORN_OUT    => 'Worn out',
        self::DISPOSAL_BROKEN      => 'Broken',
        self::DISPOSAL_LOST_STOLEN => 'Lost or stolen',
        self::DISPOSAL_SOLD        => 'Sold',
        self::DISPOSAL_GIVEN_AWAY  => 'Given away',
        self::DISPOSAL_OTHER       => 'Other',
    ];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 150)]
    private string $name = '';

    #[ORM\Column(length: 30)]
    private string $category = self::CATEGORY_OTHER;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $description = null;

    /** 1 for an individual item; a batch's count, reduced on partial disposal. */
    #[ORM\Column]
    private int $quantity = 1;

    /** Serial number or our own asset tag. */
    #[ORM\Column(length: 100, nullable: true)]
    private ?string $identifier = null;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $location = null;

    #[ORM\Column(length: 20)]
    private string $status = self::STATUS_IN_USE;

    #[ORM\Column(length: 20)]
    private string $acquisitionType = self::ACQUISITION_PURCHASED;

    /** Null when not known (older kit). */
    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $acquiredAt = null;

    /** Who it was bought from — a contact with isSupplier set. Set automatically when created from a PO receipt. */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $supplier = null;

    /** The purchase order line this was received on, when it came from a PO. */
    #[ORM\ManyToOne(targetEntity: PurchaseOrderLine::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?PurchaseOrderLine $purchaseOrderLine = null;

    /** Net total actually paid for this entry (not per unit). Null for donated or unknown. */
    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 2, nullable: true)]
    private ?string $purchaseCost = null;

    /** Net total to replace this entry today, for insurance. */
    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 2, nullable: true)]
    private ?string $replacementValue = null;

    /** When replacementValue was last set, so old valuations stand out on the insurance report. */
    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $replacementValueUpdatedAt = null;

    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $warrantyUntil = null;

    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $disposedAt = null;

    #[ORM\Column(length: 20, nullable: true)]
    private ?string $disposalReason = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $disposalNote = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $createdBy = null;

    /** @var Collection<int, AssetFile> */
    #[ORM\OneToMany(targetEntity: AssetFile::class, mappedBy: 'asset', cascade: ['remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['uploadedAt' => 'DESC'])]
    private Collection $files;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
        $this->files     = new ArrayCollection();
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

    public function getCategory(): string
    {
        return $this->category;
    }

    public function setCategory(string $category): static
    {
        $this->category = $category;
        return $this;
    }

    public function getCategoryLabel(): string
    {
        return self::CATEGORY_LABELS[$this->category] ?? $this->category;
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

    public function getQuantity(): int
    {
        return $this->quantity;
    }

    public function setQuantity(int $quantity): static
    {
        $this->quantity = $quantity;
        return $this;
    }

    public function isBatch(): bool
    {
        return $this->quantity > 1;
    }

    public function getIdentifier(): ?string
    {
        return $this->identifier;
    }

    public function setIdentifier(?string $identifier): static
    {
        $this->identifier = $identifier;
        return $this;
    }

    public function getLocation(): ?string
    {
        return $this->location;
    }

    public function setLocation(?string $location): static
    {
        $this->location = $location;
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

    public function getStatusLabel(): string
    {
        return self::STATUS_LABELS[$this->status] ?? $this->status;
    }

    public function isDisposed(): bool
    {
        return $this->status === self::STATUS_DISPOSED;
    }

    public function getAcquisitionType(): string
    {
        return $this->acquisitionType;
    }

    public function setAcquisitionType(string $acquisitionType): static
    {
        $this->acquisitionType = $acquisitionType;
        return $this;
    }

    public function getAcquisitionTypeLabel(): string
    {
        return self::ACQUISITION_LABELS[$this->acquisitionType] ?? $this->acquisitionType;
    }

    public function getAcquiredAt(): ?\DateTimeImmutable
    {
        return $this->acquiredAt;
    }

    public function setAcquiredAt(?\DateTimeImmutable $acquiredAt): static
    {
        $this->acquiredAt = $acquiredAt;
        return $this;
    }

    public function getSupplier(): ?User
    {
        return $this->supplier;
    }

    public function setSupplier(?User $supplier): static
    {
        $this->supplier = $supplier;
        return $this;
    }

    public function getPurchaseOrderLine(): ?PurchaseOrderLine
    {
        return $this->purchaseOrderLine;
    }

    public function setPurchaseOrderLine(?PurchaseOrderLine $purchaseOrderLine): static
    {
        $this->purchaseOrderLine = $purchaseOrderLine;
        return $this;
    }

    public function getPurchaseCost(): ?string
    {
        return $this->purchaseCost;
    }

    public function setPurchaseCost(?string $purchaseCost): static
    {
        $this->purchaseCost = $purchaseCost;
        return $this;
    }

    public function getReplacementValue(): ?string
    {
        return $this->replacementValue;
    }

    /** Also stamps replacementValueUpdatedAt when the value actually changes. */
    public function setReplacementValue(?string $replacementValue): static
    {
        if ($replacementValue !== $this->replacementValue) {
            $this->replacementValueUpdatedAt = $replacementValue === null ? null : new \DateTimeImmutable('today');
        }
        $this->replacementValue = $replacementValue;
        return $this;
    }

    public function getReplacementValueUpdatedAt(): ?\DateTimeImmutable
    {
        return $this->replacementValueUpdatedAt;
    }

    /** Per-unit purchase cost for a batch, for display only — the stored figure is always the total. */
    public function getUnitPurchaseCost(): ?string
    {
        return $this->purchaseCost === null ? null : number_format((float) $this->purchaseCost / max(1, $this->quantity), 2, '.', '');
    }

    /** Per-unit replacement value for a batch, for display only. */
    public function getUnitReplacementValue(): ?string
    {
        return $this->replacementValue === null ? null : number_format((float) $this->replacementValue / max(1, $this->quantity), 2, '.', '');
    }

    public function getWarrantyUntil(): ?\DateTimeImmutable
    {
        return $this->warrantyUntil;
    }

    public function setWarrantyUntil(?\DateTimeImmutable $warrantyUntil): static
    {
        $this->warrantyUntil = $warrantyUntil;
        return $this;
    }

    public function getDisposedAt(): ?\DateTimeImmutable
    {
        return $this->disposedAt;
    }

    public function getDisposalReason(): ?string
    {
        return $this->disposalReason;
    }

    public function getDisposalReasonLabel(): ?string
    {
        return $this->disposalReason === null ? null : (self::DISPOSAL_LABELS[$this->disposalReason] ?? $this->disposalReason);
    }

    public function getDisposalNote(): ?string
    {
        return $this->disposalNote;
    }

    /**
     * Takes {qty} units off the register. Disposing of fewer than the full quantity reduces the
     * batch and scales both values down in proportion; disposing of the rest marks the whole entry
     * disposed, keeping its last values so the history still shows what was written off.
     *
     * @throws \InvalidArgumentException if {qty} is out of range or the asset is already disposed
     */
    public function dispose(int $qty, string $reason, string $note, \DateTimeImmutable $on): void
    {
        if ($this->isDisposed()) {
            throw new \InvalidArgumentException('This asset has already been disposed of.');
        }
        if ($qty < 1 || $qty > $this->quantity) {
            throw new \InvalidArgumentException('Choose between 1 and ' . $this->quantity . ' to dispose of.');
        }

        if ($qty < $this->quantity) {
            $remaining = $this->quantity - $qty;
            $scale     = static fn (?string $value) => $value === null ? null : number_format((float) $value * $remaining / $this->quantity, 2, '.', '');

            $this->purchaseCost     = $scale($this->purchaseCost);
            $this->replacementValue = $scale($this->replacementValue);
            $this->quantity         = $remaining;
            return;
        }

        $this->status         = self::STATUS_DISPOSED;
        $this->disposedAt     = $on;
        $this->disposalReason = $reason;
        $this->disposalNote   = $note;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getCreatedBy(): ?User
    {
        return $this->createdBy;
    }

    public function setCreatedBy(?User $createdBy): static
    {
        $this->createdBy = $createdBy;
        return $this;
    }

    /** @return Collection<int, AssetFile> */
    public function getFiles(): Collection
    {
        return $this->files;
    }
}
