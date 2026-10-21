<?php

namespace App\Service\Mailer;

use App\Entity\Note;
use App\Entity\User;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;

/**
 * Emails a team member when someone "@"-mentions them in a note (see AdminNoteController::add()
 * and the mention picker in _notes_panel.html.twig): the note itself, who wrote it, and a link
 * straight to the record it's on.
 */
class NoteMentionMailer
{
    private const TYPE_SINGULAR = [
        Note::TYPE_MEMBER   => 'member',
        Note::TYPE_ATTENDEE => 'booking',
        Note::TYPE_EVENT    => 'event',
        Note::TYPE_PRODUCT  => 'product',
        Note::TYPE_ORDER    => 'sale',
        Note::TYPE_ASSET    => 'asset',
        Note::TYPE_PURCHASE_ORDER => 'purchase order',
    ];

    public function __construct(
        private readonly MailerInterface $mailer,
        #[Autowire('%env(MAILER_FROM)%')]      private readonly string $mailerFrom,
        #[Autowire('%env(MAILER_FROM_NAME)%')] private readonly string $mailerFromName,
    ) {}

    /** @param array{label: string, url: ?string, company?: ?string, tags?: string[], person?: User} $target Resolved via NoteableResolver::resolve($note, absolute: true). */
    public function sendMentioned(Note $note, array $target, User $mentioned, User $author): void
    {
        if (!$mentioned->getEmail()) {
            return;
        }

        $email = (new TemplatedEmail())
            ->from(new Address($this->mailerFrom, $this->mailerFromName))
            ->to($mentioned->getEmail())
            ->subject($author->getDisplayName() . ' mentioned you: ' . $target['label'])
            ->htmlTemplate('email/note_mentioned.html.twig')
            ->textTemplate('email/note_mentioned.txt.twig')
            ->context([
                'note'      => $note,
                'target'    => $target,
                'mentioned' => $mentioned,
                'author'    => $author,
                'typeLabel' => self::TYPE_SINGULAR[$note->getNoteableType()] ?? 'record',
            ]);

        $this->mailer->send($email);
    }
}
