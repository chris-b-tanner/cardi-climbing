<?php

namespace App\Service;

use App\Entity\Asset;
use App\Entity\AssetFile;
use App\Entity\User;
use Aws\S3\Exception\S3Exception;
use Aws\S3\S3Client;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Uploads a photo or document for an Asset to S3 under the `asset-files/` prefix and records it as
 * an AssetFile. Private, like UserFileUploader's documents — every download and every thumbnail
 * goes through a short-lived presigned URL.
 */
class AssetFileUploader
{
    private const MAX_UPLOAD_BYTES = 15 * 1024 * 1024; // matches public/.user.ini's raised cap
    private const URL_TTL          = '+5 minutes';

    /** @var string[] */
    private const ALLOWED_MIME_TYPES = [
        'application/pdf',
        'image/jpeg',
        'image/png',
        'image/webp',
        'application/msword',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'application/vnd.ms-excel',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    ];

    public function __construct(
        private readonly S3Client $s3Client,
        private readonly string $s3Bucket,
    ) {}

    /**
     * Validates and uploads {file} as a new {kind} file on {asset}, attributed to {uploadedBy}.
     * Returns the new AssetFile (not yet persisted) on success, or an error message to show.
     */
    public function upload(Asset $asset, UploadedFile $file, string $kind, ?User $uploadedBy): AssetFile|string
    {
        if (!$file->isValid()) {
            return 'That upload didn\'t come through properly — please try again.';
        }

        if ($file->getSize() > self::MAX_UPLOAD_BYTES) {
            return 'That file is too large — please choose one under 15MB.';
        }

        if (!in_array($file->getMimeType(), self::ALLOWED_MIME_TYPES, true)) {
            return 'Please upload a PDF, Word, Excel, or image (JPEG/PNG/WebP) file.';
        }

        $originalFilename = $file->getClientOriginalName();
        $key = sprintf('asset-files/%d/%s-%s', $asset->getId(), bin2hex(random_bytes(8)), $originalFilename);

        try {
            $this->s3Client->putObject([
                'Bucket'      => $this->s3Bucket,
                'Key'         => $key,
                'Body'        => file_get_contents($file->getPathname()),
                'ContentType' => $file->getMimeType(),
            ]);
        } catch (S3Exception) {
            return 'Something went wrong uploading that file — please try again shortly.';
        }

        $assetFile = new AssetFile();
        $assetFile->setAsset($asset);
        $assetFile->setS3Key($key);
        $assetFile->setOriginalFilename($originalFilename);
        $assetFile->setMimeType($file->getMimeType());
        $assetFile->setSizeBytes($file->getSize());
        $assetFile->setKind(array_key_exists($kind, AssetFile::KIND_LABELS) ? $kind : AssetFile::KIND_OTHER);
        $assetFile->setUploadedBy($uploadedBy);

        return $assetFile;
    }

    public function remove(AssetFile $assetFile): void
    {
        try {
            $this->s3Client->deleteObject(['Bucket' => $this->s3Bucket, 'Key' => $assetFile->getS3Key()]);
        } catch (S3Exception) {
            // An orphaned object in S3 is wasted storage, not worth failing the delete over.
        }
    }

    /** A short-lived signed URL that downloads {assetFile} as an attachment. */
    public function getDownloadUrl(AssetFile $assetFile): string
    {
        return $this->presign($assetFile, 'attachment');
    }

    /** A short-lived signed URL that shows {assetFile} inline — for photo thumbnails on the asset page. */
    public function getViewUrl(AssetFile $assetFile): string
    {
        return $this->presign($assetFile, 'inline');
    }

    private function presign(AssetFile $assetFile, string $disposition): string
    {
        $command = $this->s3Client->getCommand('GetObject', [
            'Bucket'                     => $this->s3Bucket,
            'Key'                        => $assetFile->getS3Key(),
            'ResponseContentDisposition' => $disposition . '; filename="' . str_replace('"', '', $assetFile->getOriginalFilename()) . '"',
        ]);

        return (string) $this->s3Client->createPresignedRequest($command, self::URL_TTL)->getUri();
    }
}
