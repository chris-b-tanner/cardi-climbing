<?php

namespace App\Controller;

use App\Entity\User;
use App\Service\CardService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * "Find by card" from the People list — see card-setup.md's MODE_LOOKUP. Not user-scoped like
 * AdminCardScanController's link/verify routes (there's no member id yet, that's the point), and
 * gated `ROLE_TEAM` rather than `ROLE_ADMIN` — this is a search convenience equivalent to typing a
 * name into the People list's own search box, not a credential-management action, so it doesn't
 * need the tighter gate the actual card fields sit behind.
 */
#[Route('/admin/card-lookup')]
#[IsGranted('ROLE_TEAM')]
class AdminCardLookupController extends AbstractController
{
    public function __construct(
        private readonly CardService $cardService,
    ) {}

    #[Route('', name: 'app_admin_card_lookup_start', methods: ['POST'])]
    public function start(Request $request): JsonResponse
    {
        $payload = json_decode($request->getContent(), true) ?: [];

        if (!$this->isCsrfTokenValid('card_lookup', $payload['_csrf_token'] ?? null)) {
            return new JsonResponse(['error' => 'Access denied.'], 403);
        }

        /** @var User $staff */
        $staff = $this->getUser();
        $this->cardService->armLookup($staff);

        return new JsonResponse(['ok' => true]);
    }

    #[Route('', name: 'app_admin_card_lookup_status', methods: ['GET'])]
    public function status(): JsonResponse
    {
        return new JsonResponse($this->cardService->getLookupStatus());
    }

    #[Route('', name: 'app_admin_card_lookup_cancel', methods: ['DELETE'])]
    public function cancel(Request $request): JsonResponse
    {
        $payload = json_decode($request->getContent(), true) ?: [];

        if (!$this->isCsrfTokenValid('card_lookup', $payload['_csrf_token'] ?? null)) {
            return new JsonResponse(['error' => 'Access denied.'], 403);
        }

        $this->cardService->cancelLookup();

        return new JsonResponse(['ok' => true]);
    }
}
