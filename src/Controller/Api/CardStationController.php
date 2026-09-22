<?php

namespace App\Controller\Api;

use App\Service\CardService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Server-side API the card-linking/verification station talks to — see card-setup.md. Every route
 * here authenticates the physical station device itself (a shared bearer token), not a logged-in
 * user — same shape as DoorController, but a different device/secret entirely.
 */
#[Route('/v1/card-station')]
class CardStationController extends AbstractController
{
    public function __construct(
        private readonly string $cardStationApiKey,
        private readonly CardService $cardService,
    ) {}

    /** Polled every 1-2s: whoever's currently armed for a link/verify, or null if nobody is — see card-setup.md's privacy note on why this is the only member info the station ever receives. */
    #[Route('/pending', name: 'app_api_card_station_pending', methods: ['GET'])]
    public function pending(Request $request): JsonResponse
    {
        if ($denied = $this->checkDeviceAuth($request)) {
            return $denied;
        }

        $session = $this->cardService->findArmed();

        return new JsonResponse([
            'server_time' => (new \DateTimeImmutable())->format('Y-m-d\TH:i:s\Z'),
            'pending' => $session ? [
                'mode'      => $session->getMode(),
                'user_name' => $session->getUser()->getDisplayName(),
            ] : null,
        ]);
    }

    /** One tap, reported once the station has read a UID. */
    #[Route('/scan', name: 'app_api_card_station_scan', methods: ['POST'])]
    public function scan(Request $request): JsonResponse
    {
        if ($denied = $this->checkDeviceAuth($request)) {
            return $denied;
        }

        $payload = json_decode($request->getContent(), true);
        $cardUid = is_string($payload['card_uid'] ?? null) ? strtoupper($payload['card_uid']) : '';

        if ($cardUid === '') {
            return new JsonResponse(['error' => 'card_uid is required'], 400);
        }

        return new JsonResponse(['result' => $this->cardService->submitScan($cardUid)]);
    }

    /** Bearer-token auth for the physical station device — returns a 401 JsonResponse to short-circuit with, or null if authorised. */
    private function checkDeviceAuth(Request $request): ?JsonResponse
    {
        $header = $request->headers->get('Authorization', '');

        if (!str_starts_with($header, 'Bearer ') || !hash_equals($this->cardStationApiKey, substr($header, 7))) {
            return new JsonResponse(['error' => 'Unauthorized'], 401);
        }

        return null;
    }
}
