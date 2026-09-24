<?php

namespace App\Service;

use App\Entity\UserCertification;
use Aws\S3\S3Client;
use Symfony\Component\String\Slugger\AsciiSlugger;

/**
 * Stores a certification's completion PDF (generated once, at approval — see
 * CertificationPdfGenerator) in S3 under the `certifications/` prefix, and serves it back via a
 * short-lived presigned URL. Not public, unlike avatars — a certificate can carry a member's
 * signature and personal declarations.
 */
class CertificationPdfStorage
{
    private const DOWNLOAD_URL_TTL = '+5 minutes';

    public function __construct(
        private readonly S3Client $s3Client,
        private readonly string $s3Bucket,
    ) {}

    /** Uploads {pdfContent} for {record} and records its S3 key — does not flush. */
    public function store(UserCertification $record, string $pdfContent): void
    {
        $key = sprintf('certifications/%d-%s.pdf', $record->getId(), bin2hex(random_bytes(8)));

        $this->s3Client->putObject([
            'Bucket'      => $this->s3Bucket,
            'Key'         => $key,
            'Body'        => $pdfContent,
            'ContentType' => 'application/pdf',
        ]);

        $record->setPdfS3Key($key);
        $record->setPdfGeneratedAt(new \DateTimeImmutable());
    }

    /** A short-lived signed URL for downloading {record}'s stored certificate, or null if none has been generated. */
    public function getDownloadUrl(UserCertification $record): ?string
    {
        $key = $record->getPdfS3Key();
        if ($key === null) {
            return null;
        }

        $filename = (new AsciiSlugger())->slug($record->getCertification()->getName())->lower() . '-certificate.pdf';

        $command = $this->s3Client->getCommand('GetObject', [
            'Bucket'                     => $this->s3Bucket,
            'Key'                        => $key,
            'ResponseContentDisposition' => 'attachment; filename="' . $filename . '"',
        ]);

        return (string) $this->s3Client->createPresignedRequest($command, self::DOWNLOAD_URL_TTL)->getUri();
    }
}
