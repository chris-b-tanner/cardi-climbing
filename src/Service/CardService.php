<?php

namespace App\Service;

use App\Entity\AccessCard;
use App\Entity\CardLinkSession;
use App\Entity\User;
use App\Repository\AccessCardRepository;
use App\Repository\CardLinkSessionRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Card identity/lifecycle (AccessCard) and the card-station link/verify/lookup flow
 * (CardLinkSession) — see card-setup.md.
 */
class CardService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly AccessCardRepository $accessCardRepository,
        private readonly CardLinkSessionRepository $cardLinkSessionRepository,
        private readonly UserService $userService,
    ) {}

    /** A bare hex UID, normalised to uppercase — no separators, no surrounding text. Null if it isn't one. */
    public function normalizeUid(string $raw): ?string
    {
        $hex = strtoupper(trim($raw));

        return preg_match('/^[0-9A-F]{8,32}$/', $hex) && strlen($hex) % 2 === 0 ? $hex : null;
    }

    /**
     * Links {uid} to {user} as their new active card — marking any existing active card
     * `replaced` first. Used by both manual entry and a successful station scan. Notes the
     * member's history either way (see card-setup.md's audit-trail rationale) — worded
     * differently depending on whether this is their first card or a replacement.
     *
     * @throws \InvalidArgumentException if {uid} is already claimed by any card (any status)
     */
    public function link(User $user, string $uid, ?User $staff): AccessCard
    {
        if ($this->accessCardRepository->uidExists($uid)) {
            throw new \InvalidArgumentException('That card is already registered to another member.');
        }

        $existing = $this->accessCardRepository->findActiveForUser($user);
        $existing?->markReplaced();

        $card = new AccessCard($user, $uid, $staff);
        $this->em->persist($card);
        $this->em->flush();

        $note = $existing !== null
            ? 'Access card replaced: ' . $existing->getUid() . ' → ' . $uid . '.'
            : 'Access card added: ' . $uid . '.';
        $this->userService->addNote($user, $note, $staff);

        return $card;
    }

    /** @throws \LogicException if {card} isn't currently active or locked */
    public function remove(AccessCard $card, User $staff): void
    {
        $card->remove();
        $this->em->flush();

        $this->userService->addNote($card->getUser(), 'Access card removed: ' . $card->getUid() . '.', $staff);
    }

    public function lock(AccessCard $card, User $staff): void
    {
        $card->lock($staff);
        $this->em->flush();

        $this->userService->addNote($card->getUser(), 'Access card locked: ' . $card->getUid() . '.', $staff);
    }

    public function unlock(AccessCard $card, User $staff): void
    {
        $card->unlock($staff);
        $this->em->flush();

        $this->userService->addNote($card->getUser(), 'Access card unlocked: ' . $card->getUid() . '.', $staff);
    }

    /** Grants standing door access with no attendee booking required — see door-access-spec.md § All-hours cards. Only ever takes effect while the card is also active (§ AccessCard::$allHoursAccess). */
    public function grantAllHours(AccessCard $card, User $staff): void
    {
        $card->grantAllHours();
        $this->em->flush();

        $this->userService->addNote($card->getUser(), 'Access card granted all-hours access: ' . $card->getUid() . '.', $staff);
    }

    public function revokeAllHours(AccessCard $card, User $staff): void
    {
        $card->revokeAllHours();
        $this->em->flush();

        $this->userService->addNote($card->getUser(), 'Access card\'s all-hours access revoked: ' . $card->getUid() . '.', $staff);
    }

    /**
     * Arms a link/verify session for {user} — cancelling any other user's still-pending session
     * first (card-setup.md: only one pending session across the whole system at a time).
     *
     * @throws \InvalidArgumentException if $mode is 'verify' and {user} has no active card
     */
    public function arm(User $user, string $mode, ?User $staff): CardLinkSession
    {
        if (!in_array($mode, [CardLinkSession::MODE_LINK, CardLinkSession::MODE_VERIFY], true)) {
            throw new \InvalidArgumentException('Unknown card scan mode: ' . $mode);
        }

        if ($mode === CardLinkSession::MODE_VERIFY && $this->accessCardRepository->findActiveForUser($user) === null) {
            throw new \InvalidArgumentException('This member has no card on file to verify.');
        }

        $other = $this->cardLinkSessionRepository->findOnePending();
        if ($other !== null && $other->getUser() !== $user) {
            $other->markCancelled();
        }

        $session = new CardLinkSession($user, $mode, $staff);
        $this->em->persist($session);
        $this->em->flush();

        return $session;
    }

    /** Cancels {user}'s still-pending session, if any — a no-op otherwise. */
    public function cancel(User $user): void
    {
        $session = $this->cardLinkSessionRepository->findPendingForUser($user);
        if ($session === null) {
            return;
        }

        $session->markCancelled();
        $this->em->flush();
    }

    /**
     * Arms a "find by card" lookup — unlike arm(), not about any already-known member, so it
     * always supersedes whatever else is currently pending (there's no "same target" case to
     * spare, the way re-arming link/verify for the same member has).
     */
    public function armLookup(?User $staff): CardLinkSession
    {
        $this->cardLinkSessionRepository->findOnePending()?->markCancelled();

        $session = new CardLinkSession(null, CardLinkSession::MODE_LOOKUP, $staff);
        $this->em->persist($session);
        $this->em->flush();

        return $session;
    }

    /** Cancels the current pending lookup, if any — a no-op otherwise (including if what's pending is actually a link/verify session; this only ever touches its own mode). */
    public function cancelLookup(): void
    {
        $session = $this->cardLinkSessionRepository->findOnePending();
        if ($session === null || $session->getMode() !== CardLinkSession::MODE_LOOKUP) {
            return;
        }

        $session->markCancelled();
        $this->em->flush();
    }

    /** The member currently armed, for the station's poll — null if nobody is. */
    public function findArmed(): ?CardLinkSession
    {
        return $this->cardLinkSessionRepository->findOnePending();
    }

    /**
     * Processes one tap from the station against whichever session is currently pending.
     *
     * @return string One of 'linked' | 'conflict' | 'matched' | 'mismatch' | 'found' | 'not_found' | 'expired' (nobody's armed — the session ended between the station's last poll and this tap).
     */
    public function submitScan(string $tappedUid): string
    {
        $session = $this->findArmed();
        if ($session === null) {
            return 'expired';
        }

        if ($session->getMode() === CardLinkSession::MODE_LOOKUP) {
            $matchedUser = $this->accessCardRepository->findOneByUid($tappedUid)?->getUser();

            if ($matchedUser === null) {
                $session->markNotFound($tappedUid);
                $this->em->flush();
                return 'not_found';
            }

            $session->markFound($tappedUid, $matchedUser);
            $this->em->flush();
            return 'found';
        }

        if ($session->getMode() === CardLinkSession::MODE_VERIFY) {
            $activeCard = $this->accessCardRepository->findActiveForUser($session->getUser());
            $original = $activeCard?->getUid();

            if ($original === $tappedUid) {
                $session->markMatched();
                $this->em->flush();
                return 'matched';
            }

            $matchedUser = $this->accessCardRepository->findOneByUid($tappedUid)?->getUser();
            $session->markMismatch($tappedUid, $matchedUser);
            $this->em->flush();
            return 'mismatch';
        }

        // Link.
        try {
            $this->link($session->getUser(), $tappedUid, $session->getCreatedBy());
        } catch (\InvalidArgumentException) {
            $session->markConflict();
            $this->em->flush();
            return 'conflict';
        }

        $session->markLinked($tappedUid);
        $this->em->flush();
        return 'linked';
    }

    /**
     * The admin browser's poll — a plain read, no side effects (unlike the sentinel design this
     * replaced, resolving happened already, in submitScan()).
     *
     * @return array{status: string, cardUid?: string, matchedUserName?: ?string}
     */
    public function getSessionStatus(User $user): array
    {
        $session = $this->cardLinkSessionRepository->findLatestForUser($user);

        if ($session === null || in_array($session->getStatus(), [CardLinkSession::STATUS_CANCELLED, CardLinkSession::STATUS_EXPIRED], true)) {
            return ['status' => 'idle'];
        }

        return match ($session->getStatus()) {
            CardLinkSession::STATUS_PENDING  => ['status' => 'pending'],
            CardLinkSession::STATUS_LINKED   => ['status' => 'linked', 'cardUid' => $session->getScannedUid()],
            CardLinkSession::STATUS_MATCHED  => ['status' => 'matched'],
            CardLinkSession::STATUS_CONFLICT => ['status' => 'conflict'],
            CardLinkSession::STATUS_MISMATCH => ['status' => 'mismatch', 'matchedUserName' => $session->getMatchedUser()?->getDisplayName()],
            default => ['status' => 'idle'],
        };
    }

    /**
     * The admin browser's poll for a "find by card" lookup — findLatestByMode() rather than
     * findLatestForUser() since a lookup has no target member to key by (see class docblock).
     *
     * @return array{status: string, userId?: int, userName?: string}
     */
    public function getLookupStatus(): array
    {
        $session = $this->cardLinkSessionRepository->findLatestByMode(CardLinkSession::MODE_LOOKUP);

        if ($session === null || in_array($session->getStatus(), [CardLinkSession::STATUS_CANCELLED, CardLinkSession::STATUS_EXPIRED], true)) {
            return ['status' => 'idle'];
        }

        return match ($session->getStatus()) {
            CardLinkSession::STATUS_PENDING   => ['status' => 'pending'],
            CardLinkSession::STATUS_FOUND     => ['status' => 'found', 'userId' => $session->getMatchedUser()?->getId(), 'userName' => $session->getMatchedUser()?->getDisplayName()],
            CardLinkSession::STATUS_NOT_FOUND => ['status' => 'not_found'],
            default => ['status' => 'idle'],
        };
    }
}
