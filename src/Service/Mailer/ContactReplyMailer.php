<?php

namespace App\Service\Mailer;

use App\Entity\User;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Notifies someone the moment a contact replies to one of our tracked outbound emails (the
 * "ywal-u-{id}" scheme — see WebhookController::inbound()). Goes to whichever team member the
 * contact is currently assigned to; falls back to a fixed address when nobody's picked them up
 * yet, so a reply never lands with no one to see it.
 */
class ContactReplyMailer
{
    public function __construct(
        private readonly MailerInterface $mailer,
        private readonly UrlGeneratorInterface $urlGenerator,
        #[Autowire('%env(MAILER_FROM)%')]               private readonly string $mailerFrom,
        #[Autowire('%env(MAILER_FROM_NAME)%')]          private readonly string $mailerFromName,
        #[Autowire('%env(CONTACT_REPLY_FALLBACK_EMAIL)%')] private readonly string $fallbackEmail,
    ) {}

    public function sendReplyNotification(User $contact, string $replyText): void
    {
        $assignedTo = $contact->getAssignedTo();
        $recipient  = $assignedTo && $assignedTo->getEmail() ? $assignedTo->getEmail() : $this->fallbackEmail;

        $contactUrl = $this->urlGenerator->generate(
            'app_admin_user_show',
            ['id' => $contact->getId()],
            UrlGeneratorInterface::ABSOLUTE_URL,
        );

        $email = (new TemplatedEmail())
            ->from(new Address($this->mailerFrom, $this->mailerFromName))
            ->to($recipient)
            ->subject($contact->getDisplayName() . ' replied to your email')
            ->htmlTemplate('email/contact_reply.html.twig')
            ->textTemplate('email/contact_reply.txt.twig')
            ->context([
                'contact'    => $contact,
                'contactUrl' => $contactUrl,
                'replyText'  => $replyText,
                'assigned'   => $assignedTo !== null,
            ]);

        $this->mailer->send($email);
    }
}
