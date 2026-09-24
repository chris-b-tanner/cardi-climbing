<?php

namespace App\Service;

use App\Entity\User;
use App\Entity\UserFile;
use Aws\S3\Exception\S3Exception;
use Aws\S3\S3Client;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Uploads an admin-supplied document (qualification, quote, etc.) to S3 under the `user-files/`
 * prefix and records it as a UserFile. Unlike avatars, these aren't public — every download goes
 * through a short-lived presigned URL (see getDownloadUrl()), since a bucket policy would have to
 * expose the whole prefix.
 */
class UserFileUploader
{
    private const MAX_UPLOAD_BYTES = 15 * 1024 * 1024; // matches public/.user.ini's raised cap
    private const DOWNLOAD_URL_TTL = '+5 minutes';

    /** @var string[] */
    private const ALLOWED_MIME_TYPES = [
        'application/pdf',
        'image/jpeg',
        'image/png',
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
     * Validates and uploads {file} as a new document on {user}'s profile, attributed to {uploadedBy}.
     * Returns the new UserFile (not yet persisted) on success, or an error message to show the admin.
     */
    public function upload(User $user, UploadedFile $file, ?User $uploadedBy): UserFile|string
    {
        if (!$file->isValid()) {
            return 'That upload didn\'t come through properly — please try again.';
        }

        if ($file->getSize() > self::MAX_UPLOAD_BYTES) {
            return 'That file is too large — please choose one under 15MB.';
        }

        if (!in_array($file->getMimeType(), self::ALLOWED_MIME_TYPES, true)) {
            return 'Please upload a PDF, Word, Excel, or image (JPEG/PNG) file.';
        }

        $originalFilename = $file->getClientOriginalName();
        $key = sprintf('user-files/%d/%s-%s', $user->getId(), bin2hex(random_bytes(8)), $originalFilename);

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

        $userFile = new UserFile();
        $userFile->setUser($user);
        $userFile->setS3Key($key);
        $userFile->setOriginalFilename($originalFilename);
        $userFile->setMimeType($file->getMimeType());
        $userFile->setSizeBytes($file->getSize());
        $userFile->setUploadedBy($uploadedBy);

        return $userFile;
    }

    public function remove(UserFile $userFile): void
    {
        try {
            $this->s3Client->deleteObject(['Bucket' => $this->s3Bucket, 'Key' => $userFile->getS3Key()]);
        } catch (S3Exception) {
            // An orphaned object in S3 is wasted storage, not worth failing the delete over.
        }
    }

    /** A short-lived signed URL for downloading {userFile} directly from S3. */
    public function getDownloadUrl(UserFile $userFile): string
    {
        $command = $this->s3Client->getCommand('GetObject', [
            'Bucket'                     => $this->s3Bucket,
            'Key'                        => $userFile->getS3Key(),
            'ResponseContentDisposition' => 'attachment; filename="' . str_replace('"', '', $userFile->getOriginalFilename()) . '"',
        ]);

        return (string) $this->s3Client->createPresignedRequest($command, self::DOWNLOAD_URL_TTL)->getUri();
    }
}
