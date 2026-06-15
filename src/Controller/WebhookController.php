<?php

namespace App\Controller;

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

        $fromEmail = strtolower(trim($payload['FromFull']['Email'] ?? $payload['From'] ?? ''));

        if (!$fromEmail || !filter_var($fromEmail, FILTER_VALIDATE_EMAIL)) {
            return new JsonResponse(['error' => 'No valid sender email in payload'], 422);
        }

        if ($userRepository->findOneBy(['email' => $fromEmail])) {
            return new JsonResponse(['status' => 'exists']);
        }

        $fromName = trim($payload['FromFull']['Name'] ?? '');

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
        $em->flush();

        return new JsonResponse(['status' => 'created', 'id' => $user->getId()]);
    }
}
