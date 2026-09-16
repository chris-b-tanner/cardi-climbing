<?php

namespace App\Service;

use App\Entity\Note;
use App\Entity\User;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;

/** Notifies a team member by email the moment a pinned note is assigned to them — the same information shown on their card at /admin/actions, plus a direct link to the record the note is attached to. */
class NoteAssignmentMailer
{
    private const TYPE_SINGULAR = [
        Note::TYPE_MEMBER   => 'member',
        Note::TYPE_ATTENDEE => 'booking',
        Note::TYPE_EVENT    => 'event',
        Note::TYPE_PRODUCT  => 'product',
        Note::TYPE_ORDER    => 'sale',
    ];

    /** Capitalised noun used in the subject/title, e.g. "User action assigned to you" — distinct from TYPE_SINGULAR, which stays lowercase for the "View {type}" link. */
    private const TYPE_ENTITY_NAME = [
        Note::TYPE_MEMBER   => 'User',
        Note::TYPE_ATTENDEE => 'Booking',
        Note::TYPE_EVENT    => 'Event',
        Note::TYPE_PRODUCT  => 'Product',
        Note::TYPE_ORDER    => 'Sale',
    ];

    public function __construct(
        private readonly MailerInterface $mailer,
        #[Autowire('%env(MAILER_FROM)%')]      private readonly string $mailerFrom,
        #[Autowire('%env(MAILER_FROM_NAME)%')] private readonly string $mailerFromName,
    ) {}

    /** @param array{label: string, url: ?string, company?: ?string, tags?: string[]} $target Resolved via NoteableResolver::resolve($note, absolute: true). */
    public function sendAssigned(Note $note, array $target, User $assignedBy): void
    {
        $assignee = $note->getAssignedTo();
        if (!$assignee || !$assignee->getEmail()) {
            return;
        }

        $entityName = self::TYPE_ENTITY_NAME[$note->getNoteableType()] ?? 'Record';

        $email = (new TemplatedEmail())
            ->from(new Address($this->mailerFrom, $this->mailerFromName))
            ->to($assignee->getEmail())
            ->subject($entityName . ' action assigned to you: ' . $target['label'])
            ->htmlTemplate('email/note_assigned.html.twig')
            ->textTemplate('email/note_assigned.txt.twig')
            ->context([
                'note'        => $note,
                'target'      => $target,
                'assignee'    => $assignee,
                'assignedBy'  => $assignedBy,
                'typeLabel'   => self::TYPE_SINGULAR[$note->getNoteableType()] ?? 'record',
                'entityName'  => $entityName,
            ]);

        $this->mailer->send($email);
    }
}
