<?php

namespace App\MessageHandler;

use App\Entity\Email;
use App\Entity\User;
use App\Message\SendBulkEmailMessage;
use App\Repository\UserRepository;
use App\Service\Mailer\EmailPlaceholders;
use App\Service\UserService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Mime\Address;

/**
 * Consumes one recipient at a time off the bulk_email transport (see
 * config/packages/messenger.yaml) — run via `messenger:consume bulk_email` on a Heroku
 * Scheduler job, not a worker dyno. Mirrors the per-recipient send logic that used to live
 * inline in AdminEmailController::send(); a failure here is logged and swallowed rather than
 * retried, since a bad address won't start working on retry.
 */
#[AsMessageHandler]
final class SendBulkEmailMessageHandler
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly UserRepository $userRepository,
        private readonly MailerInterface $mailer,
        private readonly UserService $userService,
        private readonly EmailPlaceholders $emailPlaceholders,
        #[Autowire('%env(MAILER_FROM)%')]      private readonly string $mailerFrom,
        #[Autowire('%env(MAILER_FROM_NAME)%')] private readonly string $mailerFromName,
    ) {}

    public function __invoke(SendBulkEmailMessage $message): void
    {
        $email = $this->em->getRepository(Email::class)->find($message->getEmailId());
        $user  = $this->userRepository->find($message->getRecipientId());

        if (!$email || !$user || !$user->getEmail()) {
            return;
        }

        /** @var User|null $sender */
        $sender = $this->userRepository->find($message->getSenderId());

        $isFixedAudience = in_array($email->getAudienceType(), [
            Email::AUDIENCE_EVENT, Email::AUDIENCE_CERTIFICATION, Email::AUDIENCE_USER,
        ], true);

        $htmlTemplate = $email->isUseBlankLayout() ? 'email/bulk_blank.html.twig' : 'email/bulk.html.twig';
        $textTemplate = $email->isUseBlankLayout() ? 'email/bulk_blank.txt.twig' : 'email/bulk.txt.twig';

        $context = [
            'subject' => $email->getSubject(),
            'body'    => $this->emailPlaceholders->apply($email->getBody(), $user),
            'user'    => $user,
        ];
        if (!$isFixedAudience) {
            $context['recipientEmail'] = $user->getEmail();
        }

        $mimeMessage = (new TemplatedEmail())
            ->from(new Address($this->mailerFrom, $this->mailerFromName))
            ->to($user->getEmail())
            ->subject($email->getSubject())
            ->htmlTemplate($htmlTemplate)
            ->textTemplate($textTemplate)
            ->context($context);

        try {
            $this->mailer->send($mimeMessage);
            // Per-recipient audit trail — "who was sent what" — linked back to the full
            // Email record (subject, body, audience) via Note::$email.
            $this->userService->addNote($user, 'Emailed: ' . $email->getSubject(), $sender, $email);
        } catch (\Throwable $e) {
            error_log('Email #' . $email->getId() . ' failed for user ' . $user->getId() . ': ' . $e->getMessage());
        }
    }
}
