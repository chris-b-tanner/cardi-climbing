<?php

namespace App\Message;

/**
 * A quick email scheduled from a member's add-note form (see AdminNoteController::add()), queued
 * on the bulk_email transport with a DelayStamp so the consumer leaves it alone until it's due —
 * see SendScheduledNoteEmailMessageHandler. Carries the id of the thread's previous note as it
 * stood when the email was scheduled, so the quote beneath it is what the admin was replying to,
 * not whatever arrives in between.
 */
final class SendScheduledNoteEmailMessage
{
    public function __construct(
        private readonly int $noteId,
        private readonly ?int $quoteNoteId,
    ) {}

    public function getNoteId(): int
    {
        return $this->noteId;
    }

    public function getQuoteNoteId(): ?int
    {
        return $this->quoteNoteId;
    }
}
