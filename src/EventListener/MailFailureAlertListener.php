<?php

namespace App\EventListener;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Mailer\Event\FailedMessageEvent;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

/**
 * Emails MAIL_FAILURE_ALERT_EMAIL whenever Postmark refuses a send — e.g. a one-to-one email from
 * an admin whose "{firstname}@" address isn't a confirmed sender signature (see
 * PersonalSenderAddress). Several send paths only error_log() a failure and carry on (the bulk and
 * scheduled-note queues), so without this nobody would hear about it. Hooked on the mailer's own
 * FailedMessageEvent so it covers every send in the app, not just the ones that catch and log.
 *
 * The alert goes from MAILER_FROM, so it still arrives when the failure was a personal sender
 * address. It's never sent about itself, and its own failure (Postmark down altogether, say) is
 * only logged, so it can't loop.
 */
#[AsEventListener]
class MailFailureAlertListener
{
    private const ALERT_HEADER = 'X-Ywal-Failure-Alert';

    public function __construct(
        private readonly MailerInterface $mailer,
        #[Autowire('%env(MAILER_FROM)%')]              private readonly string $mailerFrom,
        #[Autowire('%env(MAILER_FROM_NAME)%')]         private readonly string $mailerFromName,
        #[Autowire('%env(MAIL_FAILURE_ALERT_EMAIL)%')] private readonly string $alertEmail,
    ) {}

    public function __invoke(FailedMessageEvent $event): void
    {
        $failed = $event->getMessage();

        if (!$failed instanceof Email || $failed->getHeaders()->has(self::ALERT_HEADER)) {
            return;
        }

        $list = static fn (array $addresses): string => implode(', ', array_map(
            static fn (Address $address): string => $address->toString(),
            $addresses,
        )) ?: '(none)';

        $alert = (new Email())
            ->from(new Address($this->mailerFrom, $this->mailerFromName))
            ->to($this->alertEmail)
            ->subject('Email failed to send: ' . ($failed->getSubject() ?? '(no subject)'))
            ->text(implode("\n", [
                'Postmark refused to send an email.',
                '',
                'Error: ' . $event->getError()->getMessage(),
                '',
                'From: ' . $list($failed->getFrom()),
                'To: ' . $list($failed->getTo()),
                'Subject: ' . ($failed->getSubject() ?? '(no subject)'),
                'Time: ' . (new \DateTimeImmutable('now', new \DateTimeZone('Europe/London')))->format('j M Y, H:i:s'),
            ]));
        $alert->getHeaders()->addTextHeader(self::ALERT_HEADER, '1');

        try {
            $this->mailer->send($alert);
        } catch (\Throwable $e) {
            error_log('Mail failure alert could not be sent: ' . $e->getMessage());
        }
    }
}
