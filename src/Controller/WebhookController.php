<?php

namespace App\Controller;

use App\Entity\Note;
use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;

class WebhookController extends AbstractController
{
    public function __construct(
        private readonly string $webhookSecret,
    ) {}

    #[Route('/webhook/inbound/{secret}', name: 'app_webhook_inbound', methods: ['POST'])]
    public function inbound(
        Request $request,
        string $secret,
        UserRepository $userRepository,
        EntityManagerInterface $em,
        UserPasswordHasherInterface $hasher,
    ): JsonResponse {
        if (!hash_equals($this->webhookSecret, $secret)) {
            return new JsonResponse(['error' => 'Unauthorized'], 401);
        }

        $payload = json_decode($request->getContent(), true);

        if (!is_array($payload)) {
            return new JsonResponse(['error' => 'Invalid payload'], 400);
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

        $user     = $userRepository->findByAnyEmail($fromEmail);
        $textBody = trim($payload['TextBody'] ?? '');

        if ($user !== null) {
            if ($textBody !== '') {
                $note = new Note();
                $note->setUser($user);
                $note->setContent($textBody);
                $em->persist($note);
                $em->flush();
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

        $user = new User();
        $user->setEmail($fromEmail);
        $user->setFirstName($firstName);
        $user->setLastName($lastName);
        $user->setPassword($hasher->hashPassword($user, bin2hex(random_bytes(16))));

        $em->persist($user);

        $sourceNote = new Note();
        $sourceNote->setUser($user);
        $sourceNote->setContent('Contact added via inbound email from ' . $fromEmail . '.');
        $em->persist($sourceNote);

        if ($textBody !== '') {
            $note = new Note();
            $note->setUser($user);
            $note->setContent($textBody);
            $em->persist($note);
        }

        $em->flush();

        return new JsonResponse(['status' => 'created', 'id' => $user->getId()]);
    }

    private function parseForwardedSender(string $text): ?array
    {
        // Only parse if the body contains a recognised forward marker
        $markers = [
            '---------- Forwarded message',
            'Begin forwarded message',
            '-----Original Message-----',
            'Forwarded message',
        ];

        $isForward = false;
        foreach ($markers as $marker) {
            if (stripos($text, $marker) !== false) {
                $isForward = true;
                break;
            }
        }

        if (!$isForward) {
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
}
