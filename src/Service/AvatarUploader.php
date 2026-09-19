<?php

namespace App\Service;

use App\Entity\User;
use Aws\S3\Exception\S3Exception;
use Aws\S3\S3Client;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Resizes/re-encodes a member's uploaded photo and stores it in S3, under the `avatars/` prefix.
 * Served as a plain public URL, not a signed one — a profile photo isn't sensitive content, and a
 * signed-URL scheme would add real complexity (regenerating/caching URLs, expiry handling) for no
 * benefit here. This relies on a bucket policy granting public GetObject on `avatars/*` — not an
 * object-level ACL, since newer S3 buckets have "Object Ownership: Bucket owner enforced" by
 * default, which rejects ACL params outright. See the deploy note where this service is wired up.
 */
class AvatarUploader
{
    private const MAX_UPLOAD_BYTES = 5 * 1024 * 1024; // 5MB raw upload — matches public/.user.ini's raised cap
    private const DIMENSION        = 480;              // stored/served square size, in pixels
    private const JPEG_QUALITY     = 85;

    /** @var string[] */
    private const ALLOWED_MIME_TYPES = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];

    public function __construct(
        private readonly S3Client $s3Client,
        private readonly string $s3Bucket,
    ) {}

    /**
     * Validates, resizes, and uploads {file} as {user}'s new avatar, deleting whichever one they
     * had before. Returns an error message to show the user, or null on success.
     */
    public function upload(User $user, UploadedFile $file): ?string
    {
        if (!$file->isValid()) {
            return 'That upload didn\'t come through properly — please try again.';
        }

        if ($file->getSize() > self::MAX_UPLOAD_BYTES) {
            return 'That image is too large — please choose one under 5MB.';
        }

        if (!in_array($file->getMimeType(), self::ALLOWED_MIME_TYPES, true)) {
            return 'Please upload a JPEG, PNG, WebP, or GIF image.';
        }

        $contents = file_get_contents($file->getPathname());
        $source   = $contents !== false ? @imagecreatefromstring($contents) : false;

        if (!$source instanceof \GdImage) {
            return 'That doesn\'t look like a valid image — please try a different file.';
        }

        $resized = $this->resizeToSquare($source);
        imagedestroy($source);

        ob_start();
        imagejpeg($resized, null, self::JPEG_QUALITY);
        $jpegData = ob_get_clean();
        imagedestroy($resized);

        $oldPath = $user->getAvatarPath();
        $newKey  = sprintf('avatars/%d-%s.jpg', $user->getId(), bin2hex(random_bytes(8)));

        try {
            $this->s3Client->putObject([
                'Bucket'       => $this->s3Bucket,
                'Key'          => $newKey,
                'Body'         => $jpegData,
                'ContentType'  => 'image/jpeg',
                // Safe to cache aggressively — every upload gets a fresh random key, so there's
                // never a stale-cache problem to worry about.
                'CacheControl' => 'public, max-age=31536000, immutable',
            ]);
        } catch (S3Exception) {
            return 'Something went wrong uploading your photo — please try again shortly.';
        }

        $user->setAvatarPath($newKey);

        if ($oldPath !== null) {
            try {
                $this->s3Client->deleteObject(['Bucket' => $this->s3Bucket, 'Key' => $oldPath]);
            } catch (S3Exception) {
                // The new avatar is already live — an orphaned old object in S3 is wasted storage,
                // not worth failing the whole request over.
            }
        }

        return null;
    }

    public function remove(User $user): void
    {
        $path = $user->getAvatarPath();
        if ($path === null) {
            return;
        }

        try {
            $this->s3Client->deleteObject(['Bucket' => $this->s3Bucket, 'Key' => $path]);
        } catch (S3Exception) {
            // Same reasoning as above — don't block clearing the reference over a delete failure.
        }

        $user->setAvatarPath(null);
    }

    public function getUrl(User $user): ?string
    {
        $path = $user->getAvatarPath();
        return $path !== null ? $this->s3Client->getObjectUrl($this->s3Bucket, $path) : null;
    }

    /** Resizes so the shorter side is DIMENSION, then centre-crops to a DIMENSION×DIMENSION square. */
    private function resizeToSquare(\GdImage $source): \GdImage
    {
        $width  = imagesx($source);
        $height = imagesy($source);
        $scale  = self::DIMENSION / min($width, $height);

        $scaledWidth  = max((int) round($width * $scale), self::DIMENSION);
        $scaledHeight = max((int) round($height * $scale), self::DIMENSION);

        $scaled = imagecreatetruecolor($scaledWidth, $scaledHeight);
        imagecopyresampled($scaled, $source, 0, 0, 0, 0, $scaledWidth, $scaledHeight, $width, $height);

        $cropX = (int) round(($scaledWidth - self::DIMENSION) / 2);
        $cropY = (int) round(($scaledHeight - self::DIMENSION) / 2);

        $square = imagecreatetruecolor(self::DIMENSION, self::DIMENSION);
        // Flatten transparency (from a PNG/GIF/WebP source with alpha) onto white — the
        // stored/served format is always JPEG, which has no alpha channel of its own.
        $white = imagecolorallocate($square, 255, 255, 255);
        imagefill($square, 0, 0, $white);
        imagecopy($square, $scaled, 0, 0, $cropX, $cropY, self::DIMENSION, self::DIMENSION);
        imagedestroy($scaled);

        return $square;
    }
}
