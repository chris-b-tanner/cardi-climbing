<?php

namespace App\Entity;

use App\Repository\AssetFileRepository;
use Doctrine\ORM\Mapping as ORM;

/** A photo or document attached to an Asset — photos for insurance claims and telling sets of holds apart, plus receipts, manuals and warranties. Stored privately in S3; see AssetFileUploader. */
#[ORM\Entity(repositoryClass: AssetFileRepository::class)]
class AssetFile
{
    public const KIND_PHOTO    = 'photo';
    public const KIND_RECEIPT  = 'receipt';
    public const KIND_MANUAL   = 'manual';
    public const KIND_WARRANTY = 'warranty';
    public const KIND_OTHER    = 'other';

    public const KIND_LABELS = [
        self::KIND_PHOTO    => 'Photo',
        self::KIND_RECEIPT  => 'Receipt',
        self::KIND_MANUAL   => 'Manual',
        self::KIND_WARRANTY => 'Warranty',
        self::KIND_OTHER    => 'Other',
    ];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Asset::class, inversedBy: 'files')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Asset $asset;

    /** S3 object key (not a full URL), served via a short-lived presigned URL since these aren't public. */
    #[ORM\Column(length: 255)]
    private string $s3Key;

    #[ORM\Column(length: 255)]
    private string $originalFilename;

    #[ORM\Column(length: 100)]
    private string $mimeType;

    #[ORM\Column]
    private int $sizeBytes;

    #[ORM\Column(length: 20)]
    private string $kind = self::KIND_OTHER;

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

    public function getAsset(): Asset
    {
        return $this->asset;
    }

    public function setAsset(Asset $asset): static
    {
        $this->asset = $asset;
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

    public function isImage(): bool
    {
        return str_starts_with($this->mimeType, 'image/');
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

    public function getKind(): string
    {
        return $this->kind;
    }

    public function setKind(string $kind): static
    {
        $this->kind = $kind;
        return $this;
    }

    public function getKindLabel(): string
    {
        return self::KIND_LABELS[$this->kind] ?? $this->kind;
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
