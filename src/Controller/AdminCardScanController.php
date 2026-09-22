<?php

namespace App\Controller;

use App\Entity\User;
use App\Repository\AccessCardRepository;
use App\Service\CardService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Admin-side of card management — see card-setup.md. The station link/verify/lock/unlock actions
 * live on the contact show screen (templates/admin/users/show.html.twig); manual UID entry is the
 * one action still on the edit screen, for when the station isn't available. The `/card-scan`
 * flow is a small JSON API polled by a live modal; lock/unlock/manual-link are plain form POSTs
 * with a redirect + flash, matching the rest of this app's admin actions. Gated `ROLE_ADMIN`,
 * same as the card fields these replace.
 */
#[Route('/admin/users/{id}')]
#[IsGranted('ROLE_ADMIN')]
class AdminCardScanController extends AbstractController
{
    public function __construct(
        private readonly CardService $cardService,
        private readonly AccessCardRepository $accessCardRepository,
    ) {}

    #[Route('/card-scan', name: 'app_admin_user_card_scan_start', methods: ['POST'])]
    public function start(Request $request, User $user): JsonResponse
    {
        $payload = json_decode($request->getContent(), true) ?: [];

        if (!$this->isCsrfTokenValid('card_scan_' . $user->getId(), $payload['_csrf_token'] ?? null)) {
            return new JsonResponse(['error' => 'Access denied.'], 403);
        }

        $mode = $payload['mode'] ?? '';

        try {
            /** @var User $admin */
            $admin = $this->getUser();
            $this->cardService->arm($user, $mode, $admin);
        } catch (\InvalidArgumentException $e) {
            return new JsonResponse(['error' => $e->getMessage()], 400);
        }

        return new JsonResponse(['ok' => true]);
    }

    #[Route('/card-scan', name: 'app_admin_user_card_scan_status', methods: ['GET'])]
    public function status(User $user): JsonResponse
    {
        return new JsonResponse($this->cardService->getSessionStatus($user));
    }

    #[Route('/card-scan', name: 'app_admin_user_card_scan_cancel', methods: ['DELETE'])]
    public function cancel(Request $request, User $user): JsonResponse
    {
        $payload = json_decode($request->getContent(), true) ?: [];

        if (!$this->isCsrfTokenValid('card_scan_' . $user->getId(), $payload['_csrf_token'] ?? null)) {
            return new JsonResponse(['error' => 'Access denied.'], 403);
        }

        $this->cardService->cancel($user);

        return new JsonResponse(['ok' => true]);
    }

    /** Manual fallback for when the card station isn't available — synchronous, no session involved. */
    #[Route('/card-link-manual', name: 'app_admin_user_card_link_manual', methods: ['POST'])]
    public function linkManual(Request $request, User $user): Response
    {
        if (!$this->isCsrfTokenValid('card_manage_' . $user->getId(), $request->request->get('_csrf_token'))) {
            $this->addFlash('error', 'Access denied.');
            return $this->redirectToRoute('app_home');
        }

        $uid = $this->cardService->normalizeUid(trim($request->request->get('uid', '')));

        if ($uid === null) {
            $this->addFlash('error', 'That doesn\'t look like a card UID — enter just the hex UID (e.g. "B0A9FF5C").');
            return $this->redirectToRoute('app_admin_user_edit', ['id' => $user->getId()]);
        }

        try {
            /** @var User $admin */
            $admin = $this->getUser();
            $this->cardService->link($user, $uid, $admin);
            $this->addFlash('success', 'Card linked.');
        } catch (\InvalidArgumentException $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('app_admin_user_edit', ['id' => $user->getId()]);
    }

    #[Route('/card-lock', name: 'app_admin_user_card_lock', methods: ['POST'])]
    public function lock(Request $request, User $user): Response
    {
        if (!$this->isCsrfTokenValid('card_manage_' . $user->getId(), $request->request->get('_csrf_token'))) {
            $this->addFlash('error', 'Access denied.');
            return $this->redirectToRoute('app_home');
        }

        $card = $this->accessCardRepository->findCurrentForUser($user);
        if ($card === null || $card->isLocked()) {
            $this->addFlash('error', $card === null ? 'This member has no card to lock.' : 'This card is already locked.');
        } else {
            /** @var User $admin */
            $admin = $this->getUser();
            $this->cardService->lock($card, $admin);
            $this->addFlash('success', 'Card locked — it will no longer open doors, but stays linked to ' . $user->getDisplayName() . '.');
        }

        return $this->redirectToRoute('app_admin_user_show', ['id' => $user->getId()]);
    }

    #[Route('/card-unlock', name: 'app_admin_user_card_unlock', methods: ['POST'])]
    public function unlock(Request $request, User $user): Response
    {
        if (!$this->isCsrfTokenValid('card_manage_' . $user->getId(), $request->request->get('_csrf_token'))) {
            $this->addFlash('error', 'Access denied.');
            return $this->redirectToRoute('app_home');
        }

        $card = $this->accessCardRepository->findCurrentForUser($user);
        if ($card === null || !$card->isLocked()) {
            $this->addFlash('error', 'This member has no locked card to unlock.');
        } else {
            /** @var User $admin */
            $admin = $this->getUser();
            $this->cardService->unlock($card, $admin);
            $this->addFlash('success', 'Card unlocked.');
        }

        return $this->redirectToRoute('app_admin_user_show', ['id' => $user->getId()]);
    }

    /** Removes this member's card with no replacement — distinct from linking a new one, which replaces it automatically. */
    #[Route('/card-remove', name: 'app_admin_user_card_remove', methods: ['POST'])]
    public function remove(Request $request, User $user): Response
    {
        if (!$this->isCsrfTokenValid('card_manage_' . $user->getId(), $request->request->get('_csrf_token'))) {
            $this->addFlash('error', 'Access denied.');
            return $this->redirectToRoute('app_home');
        }

        $card = $this->accessCardRepository->findCurrentForUser($user);
        if ($card === null) {
            $this->addFlash('error', 'This member has no card to remove.');
        } else {
            /** @var User $admin */
            $admin = $this->getUser();
            $this->cardService->remove($card, $admin);
            $this->addFlash('success', 'Card removed.');
        }

        return $this->redirectToRoute('app_admin_user_show', ['id' => $user->getId()]);
    }
}
