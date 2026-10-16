<?php

namespace App\MessageHandler;

use App\Entity\Note;
use App\Message\SendScheduledNoteEmailMessage;
use App\Repository\NoteRepository;
use App\Repository\UserRepository;
use App\Service\Mailer\ContactQuickEmailMailer;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Sends a scheduled quick email once its DelayStamp has passed — picked up by the same
 * `messenger:consume bulk_email` Heroku Scheduler job as bulk sends, so it goes out on that job's
 * next run after the scheduled time rather than to the minute. The note itself was saved when the
 * admin scheduled it. It goes out with exactly that note's content and subject, no attachments
 * (they're never stored, so the form doesn't offer them on a scheduled send).
 *
 * Skipped if the note has been deleted (that's how a scheduled send is cancelled) or no longer
 * has Note::$emailScheduledFor set (already sent, e.g. a redelivered message). Like
 * SendBulkEmailMessageHandler, a failure is logged and swallowed rather than retried; the note
 * keeps its "Scheduled" pill so the miss is visible on the member's page.
 */
#[AsMessageHandler]
final class SendScheduledNoteEmailMessageHandler
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly NoteRepository $noteRepository,
        private readonly UserRepository $userRepository,
        private readonly ContactQuickEmailMailer $contactQuickEmailMailer,
    ) {}

    public function __invoke(SendScheduledNoteEmailMessage $message): void
    {
        $note = $this->noteRepository->find($message->getNoteId());

        if (!$note || $note->getEmailScheduledFor() === null || $note->getNoteableType() !== Note::TYPE_MEMBER) {
            return;
        }

        $contact = $this->userRepository->find($note->getNoteableId());
        if (!$contact || !$contact->getEmail()) {
            return;
        }

        $quoteNote = $message->getQuoteNoteId() ? $this->noteRepository->find($message->getQuoteNoteId()) : null;

        try {
            $this->contactQuickEmailMailer->send($contact, $note->getEmailSubject() ?? '', $note->getContent(), $quoteNote, [], $note->getEmailRef());
        } catch (\Throwable $e) {
            error_log('Scheduled note email #' . $note->getId() . ' failed for user ' . $contact->getId() . ': ' . $e->getMessage());
            return;
        }

        $note->setEmailScheduledFor(null);
        $this->em->flush();
    }
}
