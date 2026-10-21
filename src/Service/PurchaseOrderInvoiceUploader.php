<?php

namespace App\Service;

use App\Entity\PurchaseOrder;
use App\Entity\PurchaseOrderInvoice;
use App\Entity\User;
use Aws\S3\Exception\S3Exception;
use Aws\S3\S3Client;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Uploads a supplier's invoice (or credit note) for a purchase order to S3 under the
 * `purchase-order-invoices/` prefix and records it as a PurchaseOrderInvoice. Private, like
 * UserFileUploader's documents — every download goes through a short-lived presigned URL.
 */
class PurchaseOrderInvoiceUploader
{
    private const MAX_UPLOAD_BYTES = 15 * 1024 * 1024; // matches public/.user.ini's raised cap
    private const DOWNLOAD_URL_TTL = '+5 minutes';

    /** @var string[] */
    private const ALLOWED_MIME_TYPES = [
        'application/pdf',
        'image/jpeg',
        'image/png',
        'image/webp',
    ];

    public function __construct(
        private readonly S3Client $s3Client,
        private readonly string $s3Bucket,
    ) {}

    /** Returns the new PurchaseOrderInvoice (not yet persisted) on success, or an error message to show. */
    public function upload(PurchaseOrder $po, UploadedFile $file, ?string $invoiceNumber, ?\DateTimeImmutable $invoiceDate, ?User $uploadedBy): PurchaseOrderInvoice|string
    {
        if (!$file->isValid()) {
            return 'That upload didn\'t come through properly — please try again.';
        }

        if ($file->getSize() > self::MAX_UPLOAD_BYTES) {
            return 'That file is too large — please choose one under 15MB.';
        }

        if (!in_array($file->getMimeType(), self::ALLOWED_MIME_TYPES, true)) {
            return 'Please upload a PDF or a photo (JPEG/PNG/WebP).';
        }

        $originalFilename = $file->getClientOriginalName();
        $key = sprintf('purchase-order-invoices/%d/%s-%s', $po->getId(), bin2hex(random_bytes(8)), $originalFilename);

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

        $invoice = new PurchaseOrderInvoice();
        $invoice->setPurchaseOrder($po);
        $invoice->setInvoiceNumber($invoiceNumber);
        $invoice->setInvoiceDate($invoiceDate);
        $invoice->setS3Key($key);
        $invoice->setOriginalFilename($originalFilename);
        $invoice->setMimeType($file->getMimeType());
        $invoice->setSizeBytes($file->getSize());
        $invoice->setUploadedBy($uploadedBy);

        return $invoice;
    }

    public function remove(PurchaseOrderInvoice $invoice): void
    {
        try {
            $this->s3Client->deleteObject(['Bucket' => $this->s3Bucket, 'Key' => $invoice->getS3Key()]);
        } catch (S3Exception) {
            // An orphaned object in S3 is wasted storage, not worth failing the delete over.
        }
    }

    public function getDownloadUrl(PurchaseOrderInvoice $invoice): string
    {
        $command = $this->s3Client->getCommand('GetObject', [
            'Bucket'                     => $this->s3Bucket,
            'Key'                        => $invoice->getS3Key(),
            'ResponseContentDisposition' => 'attachment; filename="' . str_replace('"', '', $invoice->getOriginalFilename()) . '"',
        ]);

        return (string) $this->s3Client->createPresignedRequest($command, self::DOWNLOAD_URL_TTL)->getUri();
    }
}
