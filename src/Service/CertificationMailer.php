<?php

namespace App\Service;

use App\Entity\UserCertification;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\String\Slugger\AsciiSlugger;

/** Sends certification-related emails: the "please complete your induction" invite, and the completion PDF snapshot. */
class CertificationMailer
{
    public function __construct(
        private readonly MailerInterface $mailer,
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly MagicLinkService $magicLinkService,
        #[Autowire('%env(MAILER_FROM)%')]      private readonly string $mailerFrom,
        #[Autowire('%env(MAILER_FROM_NAME)%')] private readonly string $mailerFromName,
    ) {}

    public function sendInvitation(UserCertification $record): void
    {
        $holder    = $record->getUser();
        // A dependent has no login of their own, so the notification — and the "complete this"
        // link it carries — goes to their family parent, who signs in as themself to act on it.
        $recipient = $holder->getParent() ?? $holder;

        // A magic link rather than a plain one: many contacts (anyone added by an admin, or a
        // dependent) have no password to log in with at all, so a plain link would just strand
        // them at the login screen. This logs $recipient straight in and on to the complete page.
        $completeUrl = $this->urlGenerator->generate('app_magic_link', [
            'token' => $this->magicLinkService->generate(
                $recipient,
                $this->urlGenerator->generate('app_account_certification_complete', ['recordId' => $record->getId()]),
            ),
        ], UrlGeneratorInterface::ABSOLUTE_URL);

        $email = (new TemplatedEmail())
            ->from(new Address($this->mailerFrom, $this->mailerFromName))
            ->to($holder->getCertificationNotificationEmail())
            ->subject('Complete your ' . $record->getCertification()->getName() . ' certification — Y Wal')
            ->htmlTemplate('email/certification_invite.html.twig')
            ->textTemplate('email/certification_invite.txt.twig')
            ->context([
                'user'         => $recipient,
                'holder'       => $holder,
                'record'       => $record,
                'completeUrl'  => $completeUrl,
            ]);

        $this->mailer->send($email);
    }

    public function sendCompletion(UserCertification $record, string $pdfContent): void
    {
        $holder    = $record->getUser();
        $recipient = $holder->getParent() ?? $holder;

        $filename = (new AsciiSlugger())->slug($record->getCertification()->getName())->lower() . '-certificate.pdf';

        $viewUrl = $this->urlGenerator->generate(
            'app_account_certification_view',
            ['recordId' => $record->getId()],
            UrlGeneratorInterface::ABSOLUTE_URL,
        );

        $email = (new TemplatedEmail())
            ->from(new Address($this->mailerFrom, $this->mailerFromName))
            ->to($holder->getCertificationNotificationEmail())
            ->subject($record->getCertification()->getName() . ' completed — Y Wal')
            ->htmlTemplate('email/certification_completed.html.twig')
            ->textTemplate('email/certification_completed.txt.twig')
            ->context([
                'user'    => $recipient,
                'holder'  => $holder,
                'record'  => $record,
                'viewUrl' => $viewUrl,
            ])
            ->attach($pdfContent, $filename, 'application/pdf');

        $this->mailer->send($email);
    }
}
