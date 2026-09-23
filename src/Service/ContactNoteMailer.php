<?php

namespace App\Service;

use App\Entity\Note;
use App\Entity\User;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;

/**
 * Notifies a contact's assigned team member by email whenever a new note is added about that
 * contact — see AdminNoteController::add(). Distinct from NoteAssignmentMailer, which fires when
 * the *note itself* gets handed to someone as a task; this fires because of who's handling the
 * *contact* the note is about, regardless of whether the note is pinned/assigned at all.
 */
class ContactNoteMailer
{
    public function __construct(
        private readonly MailerInterface $mailer,
        #[Autowire('%env(MAILER_FROM)%')]      private readonly string $mailerFrom,
        #[Autowire('%env(MAILER_FROM_NAME)%')] private readonly string $mailerFromName,
    ) {}

    /** @param array{label: string, url: ?string, company?: ?string, tags?: string[]} $target Resolved via NoteableResolver::resolve($note, absolute: true). */
    public function sendNoteAdded(Note $note, User $contact, array $target, User $addedBy): void
    {
        $assignee = $contact->getAssignedTo();

        // No assignee, no email on file, or the assignee is the one who just wrote it — nothing to tell them they don't already know.
        if (!$assignee || !$assignee->getEmail() || $assignee === $addedBy) {
            return;
        }

        $email = (new TemplatedEmail())
            ->from(new Address($this->mailerFrom, $this->mailerFromName))
            ->to($assignee->getEmail())
            ->subject('New note on ' . $target['label'])
            ->htmlTemplate('email/contact_note_added.html.twig')
            ->textTemplate('email/contact_note_added.txt.twig')
            ->context([
                'note'     => $note,
                'target'   => $target,
                'assignee' => $assignee,
                'addedBy'  => $addedBy,
            ]);

        $this->mailer->send($email);
    }
}
