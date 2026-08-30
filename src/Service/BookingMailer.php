<?php

namespace App\Service;

use App\Entity\Event;
use App\Entity\User;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;

/** Sends the booking confirmation email — shared by the public booking flow and admin manual bookings. */
class BookingMailer
{
    public function __construct(
        private readonly MailerInterface $mailer,
        #[Autowire('%env(MAILER_FROM)%')]      private readonly string $mailerFrom,
        #[Autowire('%env(MAILER_FROM_NAME)%')] private readonly string $mailerFromName,
    ) {}

    public function sendBookingConfirmation(User $user, Event $event, \DateTimeImmutable $occurrenceDate): void
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
            ]);

        $this->mailer->send($email);
    }
}
