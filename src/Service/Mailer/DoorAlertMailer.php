<?php

namespace App\Service\Mailer;

use App\Entity\DoorLog;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;

/**
 * Sends an immediate alert email for a door_propped/unexpected_open diagnostic log entry — see
 * door-access-spec.md § Server-side alerting. Nobody's watching the on-site beeper at 2am, so
 * this exists to get the same "something is happening at the door right now" urgency into an
 * inbox almost as fast. Recipient is a single fixed address for now — no distribution list/admin
 * UI for managing it yet, per the spec's still-open item on that.
 */
class DoorAlertMailer
{
    public function __construct(
        private readonly MailerInterface $mailer,
        #[Autowire('%env(MAILER_FROM)%')]      private readonly string $mailerFrom,
        #[Autowire('%env(MAILER_FROM_NAME)%')] private readonly string $mailerFromName,
        #[Autowire('%env(DOOR_ALERT_EMAIL)%')] private readonly string $doorAlertEmail,
    ) {}

    public function sendAlert(DoorLog $log): void
    {
        $reasonLabel = match ($log->getReason()) {
            'door_propped'         => 'Door propped open',
            'door_unexpected_open' => 'Unexpected door open',
            default                => $log->getReason(),
        };

        $email = (new TemplatedEmail())
            ->from(new Address($this->mailerFrom, $this->mailerFromName))
            ->to($this->doorAlertEmail)
            ->subject('Door alert: ' . $reasonLabel)
            ->htmlTemplate('email/door_alert.html.twig')
            ->textTemplate('email/door_alert.txt.twig')
            ->context([
                'log'         => $log,
                'reasonLabel' => $reasonLabel,
            ]);

        $this->mailer->send($email);
    }
}
