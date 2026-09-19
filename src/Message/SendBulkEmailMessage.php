<?php

namespace App\Message;

/**
 * One recipient's worth of an admin-composed bulk send (see AdminEmailController::send()).
 * Queued instead of sent inline so a large audience can't blow the request timeout — carries
 * ids rather than entities since it's serialized onto the bulk_email transport and handled in
 * a later, unrelated request/process (see SendBulkEmailMessageHandler).
 */
final class SendBulkEmailMessage
{
    public function __construct(
        private readonly int $emailId,
        private readonly int $recipientId,
        private readonly int $senderId,
    ) {}

    public function getEmailId(): int
    {
        return $this->emailId;
    }

    public function getRecipientId(): int
    {
        return $this->recipientId;
    }

    public function getSenderId(): int
    {
        return $this->senderId;
    }
}
