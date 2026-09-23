<?php

namespace App\Service\Mailer;

use App\Entity\Note;
use App\Entity\User;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;

/**
 * Sends a note straight to its member as a plain, blank-templated email — the lightweight
 * continuation of a conversation that started via the full compose screen (AdminEmailController),
 * for when drafting a whole new Email record is more ceremony than a quick reply needs. The
 * subject is always the admin's own typed text (required on the add-note form whenever "Email
 * {address}" is ticked — see AdminNoteController::add()), never a fixed default, so a reply in the
 * recipient's inbox threads under whatever they'd actually expect — the add-note form defaults it
 * to "Re: {previous subject}" via Note::$emailSubject / NoteRepository::findLatestEmailThreadNote().
 * Reuses `email/bulk_blank.*.twig` (the same layout a single-member compose send already uses), so
 * it carries the same "ywal-u-{id}" line — see WebhookController::inbound() — meaning a reply pipes
 * straight back into this member's notes exactly as it would for a compose-screen send, which is
 * also where that reply's own Note picks up its own Note::$emailSubject, keeping the thread's
 * subject current no matter which side spoke last.
 *
 * {@see $quoteNote}: the previous turn in this same thread (whichever side sent it), quoted beneath
 * the new message — "On {date}, {name} wrote: > ..." — exactly like a normal reply, so the whole
 * back-and-forth reads as one conversation in the recipient's inbox even though nothing on our side
 * threads them together beyond the subject line matching. The quote is rendered into the outgoing
 * email only, never written back into the new Note — that stays exactly what the admin typed, so
 * the notes trail doesn't accumulate a duplicate copy of every prior message.
 *
 * No `recipientEmail` in the context (unlike a bulk send), so no unsubscribe link — this is 1:1
 * correspondence, not marketing mail. Doesn't create a separate Email row or a bulk-style
 * "Emailed: ..." note — the note the admin just typed (see AdminNoteController::add()) is already
 * the record of what was sent.
 */
class ContactQuickEmailMailer
{
    /**
     * A quick-email note's content is stored verbatim now — an envelope icon + Note::$emailSubject
     * marks it as sent, not a text prefix (see AdminNoteController::add()) — but this stays to
     * gracefully strip the literal "Emailed: " prefix any note created before that change still
     * carries, so quoting an old thread doesn't leak that internal marker into the recipient's inbox.
     */
    private const SENT_PREFIX = 'Emailed: ';

    public function __construct(
        private readonly MailerInterface $mailer,
        private readonly EmailPlaceholders $emailPlaceholders,
        #[Autowire('%env(MAILER_FROM)%')]      private readonly string $mailerFrom,
        #[Autowire('%env(MAILER_FROM_NAME)%')] private readonly string $mailerFromName,
    ) {}

    public function send(User $contact, string $subject, string $body, ?Note $quoteNote = null): void
    {
        if (!$contact->getEmail()) {
            return;
        }

        $context = [
            'subject' => $subject,
            'body'    => $this->emailPlaceholders->apply($body, $contact),
            'user'    => $contact,
        ];

        if ($quoteNote !== null) {
            $context['quote'] = $this->buildQuote($quoteNote, $contact);
        }

        $email = (new TemplatedEmail())
            ->from(new Address($this->mailerFrom, $this->mailerFromName))
            ->to($contact->getEmail())
            ->subject($subject)
            ->htmlTemplate('email/bulk_blank.html.twig')
            ->textTemplate('email/bulk_blank.txt.twig')
            ->context($context);

        $this->mailer->send($email);
    }

    /** @return array{author: string, date: string, text: string} */
    private function buildQuote(Note $quoteNote, User $contact): array
    {
        $text = $quoteNote->getContent();
        if (str_starts_with($text, self::SENT_PREFIX)) {
            $text = substr($text, strlen(self::SENT_PREFIX));
        }

        return [
            // An inbound reply's note has no addedBy (see WebhookController::inbound()) — that's
            // the member's own words coming back to them, so it's their name, not "System".
            'author' => $quoteNote->getAddedBy()?->getDisplayName() ?? $contact->getDisplayName(),
            'date'   => $quoteNote->getCreatedAt()->format('j M Y \a\t H:i'),
            'text'   => $text,
        ];
    }
}
