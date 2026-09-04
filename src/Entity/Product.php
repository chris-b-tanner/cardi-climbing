<?php

namespace App\Entity;

use App\Repository\ProductRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * A sellable item. Holds the fields common to every product type; SalesOrderRow (to follow) will
 * reference products through this single productId regardless of type. Type-specific fields live
 * in a thin 1:1 extension table keyed on productId — see StockProduct, CreditProduct,
 * MembershipProduct, EventTicketProduct. Service products need no extension table.
 */
#[ORM\Entity(repositoryClass: ProductRepository::class)]
class Product
{
    public const TYPE_STOCK        = 'stock';
    public const TYPE_SERVICE      = 'service';
    public const TYPE_CREDIT       = 'credit';
    public const TYPE_MEMBERSHIP   = 'membership';
    public const TYPE_EVENT_TICKET = 'event_ticket';

    public const VAT_STANDARD = 'standard';
    public const VAT_REDUCED  = 'reduced';
    public const VAT_ZERO     = 'zero';
    public const VAT_EXEMPT   = 'exempt';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 150)]
    private string $name;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $shortDescription = null;

    /**
     * Products sharing the same name form a flat variant group (e.g. several "Climbing shoes"
     * products). variantName/variantValue distinguish the variants within that group (e.g.
     * "Size" / "Small") — both are per-product; only the group name is kept in sync across them.
     */
    #[ORM\Column(length: 100, nullable: true)]
    private ?string $variantName = null;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $variantValue = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 8, scale: 2)]
    private string $price;

    #[ORM\Column(length: 20)]
    private string $vatCode = self::VAT_STANDARD;

    #[ORM\Column(length: 20)]
    private string $productType;

    #[ORM\Column(options: ['default' => true])]
    private bool $isActive = true;

    #[ORM\OneToOne(mappedBy: 'product', targetEntity: StockProduct::class, cascade: ['persist', 'remove'])]
    private ?StockProduct $stockProduct = null;

    #[ORM\OneToOne(mappedBy: 'product', targetEntity: CreditProduct::class, cascade: ['persist', 'remove'])]
    private ?CreditProduct $creditProduct = null;

    #[ORM\OneToOne(mappedBy: 'product', targetEntity: MembershipProduct::class, cascade: ['persist', 'remove'])]
    private ?MembershipProduct $membershipProduct = null;

    #[ORM\OneToOne(mappedBy: 'product', targetEntity: EventTicketProduct::class, cascade: ['persist', 'remove'])]
    private ?EventTicketProduct $eventTicketProduct = null;

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

    public function getShortDescription(): ?string
    {
        return $this->shortDescription;
    }

    public function setShortDescription(?string $shortDescription): static
    {
        $this->shortDescription = $shortDescription;
        return $this;
    }

    public function getVariantName(): ?string
    {
        return $this->variantName;
    }

    public function setVariantName(?string $variantName): static
    {
        $this->variantName = $variantName;
        return $this;
    }

    public function getVariantValue(): ?string
    {
        return $this->variantValue;
    }

    public function setVariantValue(?string $variantValue): static
    {
        $this->variantValue = $variantValue;
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
        return match ($this->vatCode) {
            self::VAT_STANDARD => 'Standard rate',
            self::VAT_REDUCED => 'Reduced rate',
            self::VAT_ZERO => 'Zero rated',
            self::VAT_EXEMPT => 'Exempt',
            default => $this->vatCode,
        };
    }

    public function getProductType(): string
    {
        return $this->productType;
    }

    public function setProductType(string $productType): static
    {
        $this->productType = $productType;
        return $this;
    }

    public function getProductTypeLabel(): string
    {
        return match ($this->productType) {
            self::TYPE_STOCK => 'Stock',
            self::TYPE_SERVICE => 'Service',
            self::TYPE_CREDIT => 'Credit',
            self::TYPE_MEMBERSHIP => 'Membership',
            self::TYPE_EVENT_TICKET => 'Event ticket',
            default => $this->productType,
        };
    }

    public function isActive(): bool
    {
        return $this->isActive;
    }

    public function setIsActive(bool $isActive): static
    {
        $this->isActive = $isActive;
        return $this;
    }

    public function getStockProduct(): ?StockProduct
    {
        return $this->stockProduct;
    }

    public function getCreditProduct(): ?CreditProduct
    {
        return $this->creditProduct;
    }

    public function getMembershipProduct(): ?MembershipProduct
    {
        return $this->membershipProduct;
    }

    public function getEventTicketProduct(): ?EventTicketProduct
    {
        return $this->eventTicketProduct;
    }
}
