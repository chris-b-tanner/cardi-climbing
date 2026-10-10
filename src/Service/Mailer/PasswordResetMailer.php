<?php

namespace App\Service\Mailer;

use App\Entity\User;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use SymfonyCasts\Bundle\ResetPassword\Model\ResetPasswordToken;

/** Sends the "forgot password" reset link — see ResetPasswordController. */
class PasswordResetMailer
{
    public function __construct(
        private readonly MailerInterface $mailer,
        #[Autowire('%env(MAILER_FROM)%')]      private readonly string $mailerFrom,
        #[Autowire('%env(MAILER_FROM_NAME)%')] private readonly string $mailerFromName,
    ) {}

    public function sendResetLink(User $user, ResetPasswordToken $resetToken): void
    {
        $email = (new TemplatedEmail())
            ->from(new Address($this->mailerFrom, $this->mailerFromName))
            ->to((string) $user->getEmail())
            ->subject('Password reset request — Y Wal')
            ->htmlTemplate('email/reset_password.html.twig')
            ->textTemplate('email/reset_password.txt.twig')
            ->context(['resetToken' => $resetToken, 'user' => $user]);

        $this->mailer->send($email);
    }
}
