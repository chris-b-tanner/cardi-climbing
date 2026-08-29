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
        #[Autowire('%env(MAILER_FROM)%')]      private readonly string $mailerFrom,
        #[Autowire('%env(MAILER_FROM_NAME)%')] private readonly string $mailerFromName,
    ) {}

    public function sendInvitation(UserCertification $record): void
    {
        $completeUrl = $this->urlGenerator->generate(
            'app_account_certification_complete',
            ['recordId' => $record->getId()],
            UrlGeneratorInterface::ABSOLUTE_URL,
        );

        $email = (new TemplatedEmail())
            ->from(new Address($this->mailerFrom, $this->mailerFromName))
            ->to($record->getUser()->getEmail())
            ->subject('Complete your ' . $record->getCertification()->getName() . ' certification — Y Wal')
            ->htmlTemplate('email/certification_invite.html.twig')
            ->textTemplate('email/certification_invite.txt.twig')
            ->context([
                'user'         => $record->getUser(),
                'record'       => $record,
                'completeUrl'  => $completeUrl,
            ]);

        $this->mailer->send($email);
    }

    public function sendCompletion(UserCertification $record, string $pdfContent): void
    {
        $filename = (new AsciiSlugger())->slug($record->getCertification()->getName())->lower() . '-certificate.pdf';

        $email = (new TemplatedEmail())
            ->from(new Address($this->mailerFrom, $this->mailerFromName))
            ->to($record->getUser()->getEmail())
            ->subject($record->getCertification()->getName() . ' completed — Y Wal')
            ->htmlTemplate('email/certification_completed.html.twig')
            ->textTemplate('email/certification_completed.txt.twig')
            ->context([
                'user'   => $record->getUser(),
                'record' => $record,
            ])
            ->attach($pdfContent, $filename, 'application/pdf');

        $this->mailer->send($email);
    }
}
