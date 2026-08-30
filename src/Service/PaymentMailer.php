<?php

namespace App\Service;

use App\Entity\Payment;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;

/** Sends a receipt once a payment has succeeded — to the payer (if they have an email on file) and always to the club's own inbox. */
class PaymentMailer
{
    public function __construct(
        private readonly MailerInterface $mailer,
        #[Autowire('%env(MAILER_FROM)%')]      private readonly string $mailerFrom,
        #[Autowire('%env(MAILER_FROM_NAME)%')] private readonly string $mailerFromName,
    ) {}

    public function sendReceipt(Payment $payment): void
    {
        $email = (new TemplatedEmail())
            ->from(new Address($this->mailerFrom, $this->mailerFromName))
            ->bcc($this->mailerFrom)
            ->subject('Payment receipt — Y Wal')
            ->htmlTemplate('email/payment_receipt.html.twig')
            ->textTemplate('email/payment_receipt.txt.twig')
            ->context([
                'user'    => $payment->getUser(),
                'payment' => $payment,
            ]);

        if ($payment->getUser()->getEmail()) {
            $email->to($payment->getUser()->getEmail());
        }

        $this->mailer->send($email);
    }
}
