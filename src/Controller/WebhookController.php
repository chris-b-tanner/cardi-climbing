<?php

namespace App\Controller;

use App\Repository\UserRepository;
use App\Service\ContactReplyMailer;
use App\Service\UserService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Also the landing point for the "ywal-u-{id}" reply-tracking scheme: every email sent from the
 * compose window carries a "ywal-u-{recipient's user id}" line in its footer (see
 * templates/email/bulk*.twig). A Gmail filter on that address auto-forwards any reply quoting it
 * to this same Postmark inbound endpoint — see inbound() below, which checks for that ref before
 * falling back to the generic from-email matching used for everything else forwarded here.
 */
class WebhookController extends AbstractController
{
    private const FORWARD_MARKERS = [
        '---------- Forwarded message',
        'Begin forwarded message',
        '-----Original Message-----',
        'Forwarded message',
    ];

    public function __construct(
        private readonly string $webhookSecret,
    ) {}

    #[Route('/webhook/inbound/{secret}', name: 'app_webhook_inbound', methods: ['POST'])]
    public function inbound(
        Request $request,
        string $secret,
        UserService $userService,
        UserRepository $userRepository,
        ContactReplyMailer $contactReplyMailer,
    ): JsonResponse {
        if (!hash_equals($this->webhookSecret, $secret)) {
            return new JsonResponse(['error' => 'Unauthorized'], 401);
        }

        $payload = json_decode($request->getContent(), true);

        if (!is_array($payload)) {
            return new JsonResponse(['error' => 'Invalid payload'], 400);
        }

        $textBody = trim($payload['TextBody'] ?? '');

        $trackedUserId = $this->extractTrackedUserId($textBody);
        if ($trackedUserId !== null) {
            $trackedUser = $userRepository->find($trackedUserId);
            if ($trackedUser !== null) {
                $replyText = $this->stripForwardHeader($textBody);
                if ($replyText !== '') {
                    // Stamped onto the note as Note::$emailSubject — keeps the ad hoc email
                    // thread's subject current from whichever side spoke last, so the admin's next
                    // reply defaults to "Re: {this}" rather than a stale one — see
                    // ContactQuickEmailMailer / NoteRepository::findLatestEmailThreadNote().
                    $subject = trim($payload['Subject'] ?? '') ?: null;
                    $userService->addNote($trackedUser, 'Email reply: ' . $replyText, null, null, $subject);
                    $contactReplyMailer->sendReplyNotification($trackedUser, $replyText);
                }
                return new JsonResponse(['status' => 'noted', 'id' => $trackedUser->getId()]);
            }
            // Tracked user no longer exists — fall through to the generic from-email handling below.
        }

        $original = $this->parseForwardedSender($payload['TextBody'] ?? '');

        if ($original !== null) {
            $fromEmail = $original['email'];
            $fromName  = $original['name'];
        } else {
            $fromEmail = strtolower(trim($payload['FromFull']['Email'] ?? $payload['From'] ?? ''));
            $fromName  = trim($payload['FromFull']['Name'] ?? '');
        }

        if (!$fromEmail || !filter_var($fromEmail, FILTER_VALIDATE_EMAIL)) {
            return new JsonResponse(['error' => 'No valid sender email in payload'], 422);
        }

        $user = $userService->findExistingByEmail($fromEmail);

        if ($user !== null) {
            if ($textBody !== '') {
                $userService->addNote($user, $textBody);
            }
            return new JsonResponse(['status' => 'noted', 'id' => $user->getId()]);
        }

        if ($fromName !== '') {
            $parts     = explode(' ', $fromName, 2);
            $firstName = $parts[0];
            $lastName  = $parts[1] ?? null;
        } else {
            $firstName = 'Anon ' . (new \DateTimeImmutable())->format('j M Y');
            $lastName  = null;
        }

        $user = $userService->createContact(
            email: $fromEmail,
            firstName: $firstName,
            lastName: $lastName,
            noteContent: 'Contact added via inbound email from ' . $fromEmail . '.',
        );

        if ($textBody !== '') {
            $userService->addNote($user, $textBody);
        }

        return new JsonResponse(['status' => 'created', 'id' => $user->getId()]);
    }

    private function parseForwardedSender(string $text): ?array
    {
        if (!$this->hasForwardMarker($text)) {
            return null;
        }

        // From: Display Name <email@example.com>
        if (preg_match('/^From:\s*"?([^"<\n]+?)"?\s*<([^>@\s]+@[^>\s]+)>/mi', $text, $m)) {
            return ['name' => trim($m[1]), 'email' => strtolower(trim($m[2]))];
        }

        // From: email@example.com  (no display name)
        if (preg_match('/^From:\s*([^\s<\n]+@[^\s\n]+)/mi', $text, $m)) {
            return ['name' => '', 'email' => strtolower(trim($m[1]))];
        }

        return null;
    }

    private function hasForwardMarker(string $text): bool
    {
        foreach (self::FORWARD_MARKERS as $marker) {
            if (stripos($text, $marker) !== false) {
                return true;
            }
        }

        return false;
    }

    /** Looks for the "ywal-u-{id}" ref line this app's own outbound compose emails carry — see the class docblock. */
    private function extractTrackedUserId(string $text): ?int
    {
        if (preg_match('/ywal-u-(\d+)/i', $text, $m)) {
            return (int) $m[1];
        }

        return null;
    }

    /**
     * Drops Gmail/Outlook's own "---------- Forwarded message ---------" header block (the
     * marker line plus the From/Date/Subject/To lines under it) so the note left behind is the
     * member's actual reply text, not the mechanics of how it got to us. Deliberately doesn't try
     * to strip the deeper "On ... wrote:" quoted-original tail below that — that's a much fuzzier
     * boundary to detect reliably, and it's still useful context to see what they were replying to.
     */
    private function stripForwardHeader(string $text): string
    {
        if (!$this->hasForwardMarker($text)) {
            return trim($text);
        }

        $lines      = explode("\n", $text);
        $bodyStart  = null;
        $sawMarker  = false;

        foreach ($lines as $i => $line) {
            if (!$sawMarker) {
                foreach (self::FORWARD_MARKERS as $marker) {
                    if (stripos($line, $marker) !== false) {
                        $sawMarker = true;
                        continue 2;
                    }
                }
                continue;
            }

            // First blank line after the marker ends the From/Date/Subject/To header block.
            if (trim($line) === '') {
                $bodyStart = $i + 1;
                break;
            }
        }

        if ($bodyStart === null) {
            return trim($text);
        }

        return trim(implode("\n", array_slice($lines, $bodyStart)));
    }
}
