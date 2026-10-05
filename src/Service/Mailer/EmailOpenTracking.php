<?php

namespace App\Service\Mailer;

use Symfony\Component\Mailer\Header\MetadataHeader;
use Symfony\Component\Mime\Email;

/**
 * Links a Postmark open back to the note that recorded the email. The sender generates a ref,
 * stores it on the Note (Note::$emailRef) and tags the outgoing message with it as Postmark
 * metadata; Postmark returns that metadata on its open webhook — see
 * WebhookController::postmarkOpen(). Opens only register for emails with an HTML part (Postmark
 * tracks them with a pixel) and when open tracking is switched on for the message stream in
 * Postmark's settings.
 */
final class EmailOpenTracking
{
    public const METADATA_KEY = 'note_ref';

    public static function newRef(): string
    {
        return bin2hex(random_bytes(16));
    }

    public static function tag(Email $message, string $ref): void
    {
        $message->getHeaders()->add(new MetadataHeader(self::METADATA_KEY, $ref));
    }
}
