<?php

namespace App\Service;

use App\Entity\Attendee;
use App\Entity\Event;
use App\Entity\User;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/** Sends the booking confirmation email — shared by the public booking flow and admin manual bookings. */
class BookingMailer
{
    public function __construct(
        private readonly MailerInterface $mailer,
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly MagicLinkService $magicLinkService,
        #[Autowire('%env(MAILER_FROM)%')]      private readonly string $mailerFrom,
        #[Autowire('%env(MAILER_FROM_NAME)%')] private readonly string $mailerFromName,
    ) {}

    public function sendBookingConfirmation(User $user, Event $event, \DateTimeImmutable $occurrenceDate, ?string $pin = null): void
    {
        $email = (new TemplatedEmail())
            ->from(new Address($this->mailerFrom, $this->mailerFromName))
            ->to($user->getEmail())
            ->subject('Booking confirmed: ' . $event->getTitle() . ' (' . $occurrenceDate->format('d M Y') . ') — Y Wal')
            ->htmlTemplate('email/booking_confirmation.html.twig')
            ->textTemplate('email/booking_confirmation.txt.twig')
            ->context([
                'user'           => $user,
                'event'          => $event,
                'occurrenceDate' => $occurrenceDate,
                'pin'            => $pin,
            ]);

        $this->mailer->send($email);
    }

    /**
     * Invites {attendee}'s user to confirm or decline a pending staffing assignment an admin has
     * pre-arranged for them — a magic link rather than a plain one, since a staff member may have
     * no password set at all, and it logs them straight in and on to the response page.
     */
    public function sendStaffingInvite(Attendee $attendee): void
    {
        $user = $attendee->getUser();

        $respondUrl = $this->urlGenerator->generate('app_magic_link', [
            'token' => $this->magicLinkService->generate(
                $user,
                $this->urlGenerator->generate('app_account_staffing_respond', ['id' => $attendee->getId()]),
                'P7D',
            ),
        ], UrlGeneratorInterface::ABSOLUTE_URL);

        $event          = $attendee->getEvent();
        $occurrenceDate = $attendee->getOccurrenceDate() ?? $event->getDate();

        $email = (new TemplatedEmail())
            ->from(new Address($this->mailerFrom, $this->mailerFromName))
            ->to($user->getEmail())
            // Date + time in the subject — without it, Gmail threads separate staffing requests
            // for the same event together, burying earlier ones a reply would otherwise surface.
            ->subject('Can you help staff ' . $event->getTitle() . ' — ' . $occurrenceDate->format('d M Y') . ' ' . $event->getTimeFrom() . '? — Y Wal')
            ->htmlTemplate('email/staffing_invite.html.twig')
            ->textTemplate('email/staffing_invite.txt.twig')
            ->context([
                'user'       => $user,
                'attendee'   => $attendee,
                'event'      => $event,
                'respondUrl' => $respondUrl,
            ]);

        $this->mailer->send($email);
    }
}
