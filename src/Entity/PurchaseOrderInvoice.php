<?php

namespace App\Entity;

use App\Repository\PurchaseOrderInvoiceRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/** A supplier's invoice (or credit note) uploaded to a purchase order, with its number and date. Stored privately in S3; see PurchaseOrderInvoiceUploader. Paying it is tracked in the accounts, not here. */
#[ORM\Entity(repositoryClass: PurchaseOrderInvoiceRepository::class)]
class PurchaseOrderInvoice
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: PurchaseOrder::class, inversedBy: 'invoices')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private PurchaseOrder $purchaseOrder;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $invoiceNumber = null;

    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $invoiceDate = null;

    /** S3 object key (not a full URL), served via a short-lived presigned URL. */
    #[ORM\Column(length: 255)]
    private string $s3Key;

    #[ORM\Column(length: 255)]
    private string $originalFilename;

    #[ORM\Column(length: 100)]
    private string $mimeType;

    #[ORM\Column]
    private int $sizeBytes;

    #[ORM\Column]
    private \DateTimeImmutable $uploadedAt;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $uploadedBy = null;

    public function __construct()
    {
        $this->uploadedAt = new \DateTimeImmutable();
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

    public function getInvoiceNumber(): ?string
    {
        return $this->invoiceNumber;
    }

    public function setInvoiceNumber(?string $invoiceNumber): static
    {
        $this->invoiceNumber = $invoiceNumber;
        return $this;
    }

    public function getInvoiceDate(): ?\DateTimeImmutable
    {
        return $this->invoiceDate;
    }

    public function setInvoiceDate(?\DateTimeImmutable $invoiceDate): static
    {
        $this->invoiceDate = $invoiceDate;
        return $this;
    }

    public function getS3Key(): string
    {
        return $this->s3Key;
    }

    public function setS3Key(string $s3Key): static
    {
        $this->s3Key = $s3Key;
        return $this;
    }

    public function getOriginalFilename(): string
    {
        return $this->originalFilename;
    }

    public function setOriginalFilename(string $originalFilename): static
    {
        $this->originalFilename = $originalFilename;
        return $this;
    }

    public function getMimeType(): string
    {
        return $this->mimeType;
    }

    public function setMimeType(string $mimeType): static
    {
        $this->mimeType = $mimeType;
        return $this;
    }

    public function getSizeBytes(): int
    {
        return $this->sizeBytes;
    }

    public function setSizeBytes(int $sizeBytes): static
    {
        $this->sizeBytes = $sizeBytes;
        return $this;
    }

    public function getFormattedSize(): string
    {
        return $this->sizeBytes >= 1024 * 1024
            ? round($this->sizeBytes / (1024 * 1024), 1) . ' MB'
            : round($this->sizeBytes / 1024) . ' KB';
    }

    public function getUploadedAt(): \DateTimeImmutable
    {
        return $this->uploadedAt;
    }

    public function getUploadedBy(): ?User
    {
        return $this->uploadedBy;
    }

    public function setUploadedBy(?User $uploadedBy): static
    {
        $this->uploadedBy = $uploadedBy;
        return $this;
    }
}
