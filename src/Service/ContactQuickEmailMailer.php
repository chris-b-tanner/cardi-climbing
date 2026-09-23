<?php

namespace App\Service;

use App\Entity\User;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;

/**
 * Sends a note straight to its member as a plain, blank-templated email — the lightweight
 * continuation of a conversation that started via the full compose screen (AdminEmailController),
 * for when drafting a whole new Email record is more ceremony than a quick reply needs. The
 * subject is always the admin's own typed text (required on the add-note form whenever "Email
 * {address}" is ticked — see AdminNoteController::add()), never a fixed default, so a reply in the
 * recipient's inbox threads under whatever they'd actually expect. Reuses `email/bulk_blank.*.twig`
 * (the same layout a single-member compose send already uses), so it carries the same "Ref:
 * ywal-u-{id}" line — see WebhookController::inbound() — meaning a reply pipes straight back into
 * this member's notes exactly as it would for a compose-screen send. No `recipientEmail` in the
 * context (unlike a bulk send), so no unsubscribe link — this is 1:1 correspondence, not marketing
 * mail. Doesn't touch the Note that triggered it — the note the admin just typed is already the
 * record of what was sent, so there's no separate Email row or "Emailed: ..." note the way a
 * compose-screen send creates.
 */
class ContactQuickEmailMailer
{
    public function __construct(
        private readonly MailerInterface $mailer,
        private readonly EmailPlaceholders $emailPlaceholders,
        #[Autowire('%env(MAILER_FROM)%')]      private readonly string $mailerFrom,
        #[Autowire('%env(MAILER_FROM_NAME)%')] private readonly string $mailerFromName,
    ) {}

    public function send(User $contact, string $subject, string $body): void
    {
        if (!$contact->getEmail()) {
            return;
        }

        $email = (new TemplatedEmail())
            ->from(new Address($this->mailerFrom, $this->mailerFromName))
            ->to($contact->getEmail())
            ->subject($subject)
            ->htmlTemplate('email/bulk_blank.html.twig')
            ->textTemplate('email/bulk_blank.txt.twig')
            ->context([
                'subject' => $subject,
                'body'    => $this->emailPlaceholders->apply($body, $contact),
                'user'    => $contact,
            ]);

        $this->mailer->send($email);
    }
}
