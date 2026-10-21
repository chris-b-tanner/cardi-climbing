<?php

namespace App\Service\Mailer;

use App\Entity\Note;
use App\Entity\User;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Emails everyone watching a contact (User::$watchers) when something happens on them: a team
 * note is added (AdminNoteController::add()), the contact replies by email
 * (WebhookController::inbound()), or an email goes out to them (quick email, scheduled quick
 * email, or a compose send to a fixed audience — see SendBulkEmailMessageHandler).
 *
 * Works alongside the assignee emails rather than replacing them: ContactNoteMailer and
 * ContactReplyMailer already tell the assignee about notes and replies, so an assignee who is
 * also watching is skipped for those two to save them a duplicate. Whoever caused the event (the
 * note's author, the email's sender) is never told about their own action. A watcher who has
 * since left the team is skipped too.
 */
class ContactWatchMailer
{
    public const KIND_NOTE  = 'note';
    public const KIND_REPLY = 'reply';
    public const KIND_SENT  = 'sent';

    public function __construct(
        private readonly MailerInterface $mailer,
        private readonly UrlGeneratorInterface $urlGenerator,
        #[Autowire('%env(MAILER_FROM)%')]      private readonly string $mailerFrom,
        #[Autowire('%env(MAILER_FROM_NAME)%')] private readonly string $mailerFromName,
    ) {}

    public function notifyNoteAdded(User $contact, Note $note, User $addedBy): void
    {
        $this->notify(
            $contact,
            self::KIND_NOTE,
            'New note on ' . $contact->getDisplayName(),
            ['text' => $note->getContent(), 'actor' => $addedBy],
            [$addedBy, $contact->getAssignedTo()],
        );
    }

    public function notifyReply(User $contact, string $replyText): void
    {
        $this->notify(
            $contact,
            self::KIND_REPLY,
            $contact->getDisplayName() . ' replied by email',
            ['text' => $replyText, 'actor' => null],
            [$contact->getAssignedTo()],
        );
    }

    /** $text is the message as sent when it's plain text (quick emails); null for compose sends, whose HTML body isn't repeated here — the subject and a link to the contact are enough. */
    public function notifyEmailSent(User $contact, string $subject, ?string $text, ?User $sentBy): void
    {
        $this->notify(
            $contact,
            self::KIND_SENT,
            'Email sent to ' . $contact->getDisplayName() . ': ' . $subject,
            ['text' => $text, 'actor' => $sentBy, 'emailSubject' => $subject],
            [$sentBy],
        );
    }

    /**
     * @param array<string, mixed> $context
     * @param array<?User>         $exclude
     */
    private function notify(User $contact, string $kind, string $subject, array $context, array $exclude): void
    {
        $contactUrl = null;

        foreach ($contact->getWatchers() as $watcher) {
            if (!$watcher->getEmail() || !$watcher->isStaff() || in_array($watcher, $exclude, true)) {
                continue;
            }

            $contactUrl ??= $this->urlGenerator->generate(
                'app_admin_user_show',
                ['id' => $contact->getId()],
                UrlGeneratorInterface::ABSOLUTE_URL,
            );

            $email = (new TemplatedEmail())
                ->from(new Address($this->mailerFrom, $this->mailerFromName))
                ->to($watcher->getEmail())
                ->subject($subject)
                ->htmlTemplate('email/contact_watch.html.twig')
                ->textTemplate('email/contact_watch.txt.twig')
                ->context($context + [
                    'kind'         => $kind,
                    'contact'      => $contact,
                    'contactUrl'   => $contactUrl,
                    'watcher'      => $watcher,
                    'emailSubject' => null,
                ]);

            $this->mailer->send($email);
        }
    }
}
