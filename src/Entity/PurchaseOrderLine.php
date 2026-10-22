<?php

namespace App\Entity;

use App\Repository\PurchaseOrderLineRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * One thing on a purchase order: a stock line (linked to a StockProduct, adds stock when received)
 * or a non-stock line (free text plus a spending category, recorded as spend and optionally added
 * to the asset register). Prices are net of VAT.
 */
#[ORM\Entity(repositoryClass: PurchaseOrderLineRepository::class)]
class PurchaseOrderLine
{
    public const CATEGORY_HOLDS_VOLUMES = 'holds_volumes';
    public const CATEGORY_EQUIPMENT     = 'equipment';
    public const CATEGORY_PPE           = 'ppe';
    public const CATEGORY_SETTING_TOOLS = 'setting_tools';
    public const CATEGORY_CLEANING      = 'cleaning';
    public const CATEGORY_MAINTENANCE   = 'maintenance';
    public const CATEGORY_MARKETING     = 'marketing';
    public const CATEGORY_OFFICE        = 'office';
    public const CATEGORY_OTHER         = 'other';

    public const CATEGORY_LABELS = [
        self::CATEGORY_HOLDS_VOLUMES => 'Holds & volumes',
        self::CATEGORY_EQUIPMENT     => 'Equipment',
        self::CATEGORY_PPE           => 'PPE',
        self::CATEGORY_SETTING_TOOLS => 'Setting tools',
        self::CATEGORY_CLEANING      => 'Cleaning',
        self::CATEGORY_MAINTENANCE   => 'Maintenance',
        self::CATEGORY_MARKETING     => 'Marketing',
        self::CATEGORY_OFFICE        => 'Office',
        self::CATEGORY_OTHER         => 'Other',
    ];

    /** The spend-report category for every stock line. */
    public const STOCK_CATEGORY_LABEL = 'Stock for resale';

    /** Non-stock categories that are usually assets — "Add to asset register" is ticked by default for these when receiving. */
    public const ASSET_CATEGORIES = [
        self::CATEGORY_HOLDS_VOLUMES => Asset::CATEGORY_HOLDS_VOLUMES,
        self::CATEGORY_EQUIPMENT     => Asset::CATEGORY_WALL_EQUIPMENT,
        self::CATEGORY_PPE           => Asset::CATEGORY_HIRE_KIT,
        self::CATEGORY_SETTING_TOOLS => Asset::CATEGORY_SETTING_TOOLS,
    ];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: PurchaseOrder::class, inversedBy: 'lines')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private PurchaseOrder $purchaseOrder;

    /** Set for a stock line, null for a non-stock line. Restrict delete: a product that's been ordered keeps its purchase history. */
    #[ORM\ManyToOne(targetEntity: StockProduct::class)]
    #[ORM\JoinColumn(name: 'stock_product_id', referencedColumnName: 'product_id', nullable: true)]
    private ?StockProduct $stockProduct = null;

    #[ORM\Column(length: 255)]
    private string $description = '';

    /** Non-stock lines only — see CATEGORY_*. */
    #[ORM\Column(length: 30, nullable: true)]
    private ?string $category = null;

    /** The supplier's part number, if different from our SKU. */
    #[ORM\Column(length: 100, nullable: true)]
    private ?string $supplierCode = null;

    #[ORM\Column]
    private int $qty = 1;

    /** Net, ex VAT. */
    #[ORM\Column(type: Types::DECIMAL, precision: 8, scale: 2)]
    private string $unitCost = '0.00';

    #[ORM\Column(length: 20)]
    private string $vatCode = Product::VAT_STANDARD;

    #[ORM\Column]
    private int $position = 0;

    /** @var Collection<int, PurchaseOrderReceipt> */
    #[ORM\OneToMany(targetEntity: PurchaseOrderReceipt::class, mappedBy: 'line')]
    #[ORM\OrderBy(['receivedAt' => 'ASC', 'id' => 'ASC'])]
    private Collection $receipts;

    public function __construct()
    {
        $this->receipts = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getPurchaseOrder(): PurchaseOrder
    {
        return $this->purchaseOrder;
    }

    public function setPurchaseOrder(PurchaseOrder $purchaseOrder): static
    {
        $this->purchaseOrder = $purchaseOrder;
        return $this;
    }

    public function getStockProduct(): ?StockProduct
    {
        return $this->stockProduct;
    }

    public function setStockProduct(?StockProduct $stockProduct): static
    {
        $this->stockProduct = $stockProduct;
        return $this;
    }

    public function isStockLine(): bool
    {
        return $this->stockProduct !== null;
    }

    public function getDescription(): string
    {
        return $this->description;
    }

    public function setDescription(string $description): static
    {
        $this->description = $description;
        return $this;
    }

    public function getCategory(): ?string
    {
        return $this->category;
    }

    public function setCategory(?string $category): static
    {
        $this->category = $category;
        return $this;
    }

    /** What the spend report groups this line under. */
    public function getCategoryLabel(): string
    {
        if ($this->isStockLine()) {
            return self::STOCK_CATEGORY_LABEL;
        }
        return self::CATEGORY_LABELS[$this->category ?? self::CATEGORY_OTHER] ?? (string) $this->category;
    }

    /** Whether "Add to asset register" starts ticked when receiving this line. */
    public function isUsuallyAnAsset(): bool
    {
        return !$this->isStockLine() && array_key_exists((string) $this->category, self::ASSET_CATEGORIES);
    }

    /** The asset register category a receipt of this line is filed under. */
    public function getAssetCategory(): string
    {
        return self::ASSET_CATEGORIES[(string) $this->category] ?? Asset::CATEGORY_OTHER;
    }

    public function getSupplierCode(): ?string
    {
        return $this->supplierCode;
    }

    public function setSupplierCode(?string $supplierCode): static
    {
        $this->supplierCode = $supplierCode;
        return $this;
    }

    public function getQty(): int
    {
        return $this->qty;
    }

    public function setQty(int $qty): static
    {
        $this->qty = $qty;
        return $this;
    }

    public function getUnitCost(): string
    {
        return $this->unitCost;
    }

    public function setUnitCost(string $unitCost): static
    {
        $this->unitCost = $unitCost;
        return $this;
    }

    public function getVatCode(): string
    {
        return $this->vatCode;
    }

    public function setVatCode(string $vatCode): static
    {
        $this->vatCode = $vatCode;
        return $this;
    }

    public function getVatCodeLabel(): string
    {
        return Product::VAT_LABELS[$this->vatCode] ?? $this->vatCode;
    }

    public function getNetTotal(): string
    {
        return number_format($this->qty * (float) $this->unitCost, 2, '.', '');
    }

    public function getVat(): string
    {
        return number_format((float) $this->getNetTotal() * (Product::VAT_RATES[$this->vatCode] ?? 0), 2, '.', '');
    }

    public function getGrossTotal(): string
    {
        return number_format((float) $this->getNetTotal() + (float) $this->getVat(), 2, '.', '');
    }

    public function getPosition(): int
    {
        return $this->position;
    }

    public function setPosition(int $position): static
    {
        $this->position = $position;
        return $this;
    }

    /** @return Collection<int, PurchaseOrderReceipt> */
    public function getReceipts(): Collection
    {
        return $this->receipts;
    }

    public function addReceipt(PurchaseOrderReceipt $receipt): static
    {
        if (!$this->receipts->contains($receipt)) {
            $receipt->setLine($this);
            $this->receipts->add($receipt);
        }
        return $this;
    }

    public function getQtyReceived(): int
    {
        $total = 0;
        foreach ($this->receipts as $receipt) {
            $total += $receipt->getQty();
        }
        return $total;
    }

    public function getQtyOutstanding(): int
    {
        return max(0, $this->qty - $this->getQtyReceived());
    }
}
