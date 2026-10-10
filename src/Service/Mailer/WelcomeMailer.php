<?php

namespace App\Service\Mailer;

use App\Entity\User;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/** Sends the first email a new contact gets — the welcome for a self-registered member (RegistrationController), or the thanks for a newsletter signup (SubscribeController). */
class WelcomeMailer
{
    public function __construct(
        private readonly MailerInterface $mailer,
        private readonly UrlGeneratorInterface $urlGenerator,
        #[Autowire('%env(MAILER_FROM)%')]      private readonly string $mailerFrom,
        #[Autowire('%env(MAILER_FROM_NAME)%')] private readonly string $mailerFromName,
    ) {}

    public function sendWelcome(User $user): void
    {
        $accountUrl = $this->urlGenerator->generate('app_account', [], UrlGeneratorInterface::ABSOLUTE_URL);

        $email = (new TemplatedEmail())
            ->from(new Address($this->mailerFrom, $this->mailerFromName))
            ->to($user->getEmail())
            ->subject('Welcome to Y Wal!')
            ->htmlTemplate('email/welcome.html.twig')
            ->textTemplate('email/welcome.txt.twig')
            ->context([
                'user'       => $user,
                'accountUrl' => $accountUrl,
            ]);

        $this->mailer->send($email);
    }

    public function sendSubscribeThanks(User $user): void
    {
        $email = (new TemplatedEmail())
            ->from(new Address($this->mailerFrom, $this->mailerFromName))
            ->to($user->getEmail())
            ->subject('Thanks for signing up — Y Wal')
            ->htmlTemplate('email/subscribe_thanks.html.twig')
            ->textTemplate('email/subscribe_thanks.txt.twig')
            ->context(['user' => $user, 'recipientEmail' => $user->getEmail()]);

        $this->mailer->send($email);
    }
}
