<?php

namespace App\Service\Mailer;

use App\Entity\User;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

/**
 * The From for one-to-one emails an admin sends a single member (a single-member compose send, or
 * a quick email from the member page's add-note form): "{firstname}@{our domain}", named
 * "First (Cardigan Climbing)", so it reads as coming from a person at the club. Each of those
 * addresses has to be set up as a Postmark sender signature, or Postmark refuses the send. Falls
 * back to the usual MAILER_FROM when there's no sender or they have no first name.
 *
 * Reply-To stays MAILER_FROM: replies are tracked by that inbox forwarding to Postmark's inbound
 * webhook (see WebhookController::inbound()), so a reply to the personal address would never make
 * it back onto the member's notes.
 */
class PersonalSenderAddress
{
    public function __construct(
        #[Autowire('%env(MAILER_FROM)%')]      private readonly string $mailerFrom,
        #[Autowire('%env(MAILER_FROM_NAME)%')] private readonly string $mailerFromName,
    ) {}

    public function apply(Email $email, ?User $sender): Email
    {
        $default = new Address($this->mailerFrom, $this->mailerFromName);
        $local   = $this->localPart($sender?->getFirstName());

        if ($local === '') {
            return $email->from($default);
        }

        $domain = substr($this->mailerFrom, strrpos($this->mailerFrom, '@') + 1);

        return $email
            ->from(new Address($local . '@' . $domain, trim($sender->getFirstName()) . ' (Cardigan Climbing)'))
            ->replyTo($default);
    }

    /** "Chris" → "chris"; accents dropped ("Siân" → "sian"), anything else non-alphanumeric removed. */
    private function localPart(?string $firstName): string
    {
        $ascii = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', trim($firstName ?? '')) ?: '';

        return preg_replace('/[^a-z0-9]/', '', strtolower($ascii));
    }
}
