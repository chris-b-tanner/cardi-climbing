<?php

namespace App\Controller;

use App\Repository\AccessCardRepository;
use App\Repository\CardLinkSessionRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Read-only report of every physical card the card station has ever seen a UID for — see
 * card-setup.md. Merges two sources: `access_card` (cards actually linked to a member at some
 * point, whatever their current status) and `card_link_session` (every scan that resolved a UID,
 * including ones that never became a registered card — a mismatched verify, a not-found lookup, a
 * conflicting link attempt). Door taps have their own dedicated report (Settings > Access log,
 * door-access-spec.md) and aren't merged in here — this page is specifically about the card
 * station, a different device with a different history.
 */
#[Route('/admin/settings/cards')]
#[IsGranted('ROLE_ADMIN')]
class AdminCardController extends AbstractController
{
    #[Route('', name: 'app_admin_settings_cards')]
    public function index(AccessCardRepository $accessCardRepository, CardLinkSessionRepository $cardLinkSessionRepository): Response
    {
        $cards = [];

        foreach ($accessCardRepository->findAllOrdered() as $card) {
            $cards[$card->getUid()] = [
                'uid'        => $card->getUid(),
                'owner'      => $card->getUser(),
                'status'     => $card->getStatus(),
                'lastSeenAt' => $card->getReplacedAt() ?? $card->getLockedAt() ?? $card->getDeployedAt(),
                'scanCount'  => 0,
            ];
        }

        foreach ($cardLinkSessionRepository->findAllWithScannedUid() as $session) {
            $uid = $session->getScannedUid();

            // A UID a session resolved with but that never became (or is no longer) a registered
            // card at all — e.g. a not-found lookup, or a mismatched verify against a stray tap.
            if (!isset($cards[$uid])) {
                $cards[$uid] = [
                    'uid'        => $uid,
                    'owner'      => $session->getMatchedUser(),
                    'status'     => 'unregistered',
                    'lastSeenAt' => $session->getResolvedAt() ?? $session->getCreatedAt(),
                    'scanCount'  => 0,
                ];
            }

            $cards[$uid]['scanCount']++;

            $seenAt = $session->getResolvedAt() ?? $session->getCreatedAt();
            if ($seenAt > $cards[$uid]['lastSeenAt']) {
                $cards[$uid]['lastSeenAt'] = $seenAt;
            }
        }

        usort($cards, static fn(array $a, array $b) => $b['lastSeenAt'] <=> $a['lastSeenAt']);

        return $this->render('admin/settings/cards/index.html.twig', [
            'cards' => $cards,
        ]);
    }

    #[Route('/{uid}', name: 'app_admin_settings_card_show', requirements: ['uid' => '[0-9A-F]{8,32}'])]
    public function show(string $uid, AccessCardRepository $accessCardRepository, CardLinkSessionRepository $cardLinkSessionRepository): Response
    {
        $card = $accessCardRepository->findOneByUid($uid);
        $sessions = $cardLinkSessionRepository->findByScannedUid($uid);

        if ($card === null && $sessions === []) {
            throw $this->createNotFoundException('No card with that UID has ever been seen.');
        }

        return $this->render('admin/settings/cards/show.html.twig', [
            'uid'      => $uid,
            'card'     => $card,
            'sessions' => $sessions,
        ]);
    }
}
