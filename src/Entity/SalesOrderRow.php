<?php

namespace App\Entity;

use App\Repository\SalesOrderRowRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * One line of a SalesOrder. The order carries the payer/context member; beneficiaryMember is the
 * member the item is actually for, defaulting to the order's member but overridable per line (e.g.
 * a parent buying a session for a dependent).
 */
#[ORM\Entity(repositoryClass: SalesOrderRowRepository::class)]
class SalesOrderRow
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: SalesOrder::class, inversedBy: 'rows')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private SalesOrder $order;

    #[ORM\ManyToOne(targetEntity: Product::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private Product $product;

    /**
     * The member this line is actually for — only meaningful for a credit, membership or event
     * ticket product. Null means it's the order's own member; only set here when it differs (e.g.
     * a parent buying a session for a dependent). Use getEffectiveBeneficiary() to resolve it.
     */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $beneficiaryMember = null;

    /** Which occurrence of a recurring event this line books. Null for a one-off event (or a non-event-ticket product). */
    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $occurrenceDate = null;

    #[ORM\Column]
    private int $qty = 1;

    /** Snapshot of the product's unit list price at the time of sale (ex VAT), so later price changes don't rewrite history. */
    #[ORM\Column(type: Types::DECIMAL, precision: 8, scale: 2)]
    private string $listPriceAtSale;

    /** Unit price actually charged, inc VAT — may differ from listPriceAtSale (discount, adjustment). Line total is this times qty. */
    #[ORM\Column(type: Types::DECIMAL, precision: 8, scale: 2)]
    private string $chargedPrice;

    /** Snapshot of the product's VAT code at the time of sale — see Product::VAT_* constants. */
    #[ORM\Column(length: 20)]
    private string $vatCodeAtSale;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $note = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getOrder(): SalesOrder
    {
        return $this->order;
    }

    public function setOrder(SalesOrder $order): static
    {
        $this->order = $order;
        return $this;
    }

    public function getProduct(): Product
    {
        return $this->product;
    }

    public function setProduct(Product $product): static
    {
        $this->product = $product;
        return $this;
    }

    public function getBeneficiaryMember(): ?User
    {
        return $this->beneficiaryMember;
    }

    public function setBeneficiaryMember(?User $beneficiaryMember): static
    {
        $this->beneficiaryMember = $beneficiaryMember;
        return $this;
    }

    /** The member this line is actually for: the override if set, otherwise the order's own member. */
    public function getEffectiveBeneficiary(): User
    {
        return $this->beneficiaryMember ?? $this->order->getUser();
    }

    public function getOccurrenceDate(): ?\DateTimeImmutable
    {
        return $this->occurrenceDate;
    }

    public function setOccurrenceDate(?\DateTimeImmutable $occurrenceDate): static
    {
        $this->occurrenceDate = $occurrenceDate;
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

    public function getListPriceAtSale(): string
    {
        return $this->listPriceAtSale;
    }

    public function setListPriceAtSale(string $listPriceAtSale): static
    {
        $this->listPriceAtSale = $listPriceAtSale;
        return $this;
    }

    public function getChargedPrice(): string
    {
        return $this->chargedPrice;
    }

    public function setChargedPrice(string $chargedPrice): static
    {
        $this->chargedPrice = $chargedPrice;
        return $this;
    }

    public function getVatCodeAtSale(): string
    {
        return $this->vatCodeAtSale;
    }

    public function setVatCodeAtSale(string $vatCodeAtSale): static
    {
        $this->vatCodeAtSale = $vatCodeAtSale;
        return $this;
    }

    public function getNote(): ?string
    {
        return $this->note;
    }

    public function setNote(?string $note): static
    {
        $this->note = $note;
        return $this;
    }

    /** qty × chargedPrice (inc VAT). */
    public function getLineTotal(): string
    {
        return number_format((float) $this->chargedPrice * $this->qty, 2, '.', '');
    }
}
