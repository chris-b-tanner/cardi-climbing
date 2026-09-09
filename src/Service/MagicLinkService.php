<?php

namespace App\Service;

use App\Entity\MagicLink;
use App\Entity\User;
use App\Repository\MagicLinkRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Password-free sign-in links — a random token good for logging a specific user straight in and
 * sending them on to one particular page, without them ever needing to know (or have set) a
 * password. See MagicLink for the storage shape and why it's reusable rather than single-use.
 */
class MagicLinkService
{
    private const SELECTOR_BYTES = 16; // -> 32 hex chars
    private const VERIFIER_BYTES = 32; // -> 64 hex chars

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly MagicLinkRepository $magicLinkRepository,
    ) {}

    /**
     * Creates a magic link for {user} and returns the raw token to embed in a URL (via the
     * app_magic_link route, e.g. `/go/{token}`) — this raw form is never itself stored or logged.
     *
     * @param ?string $redirectPath A local path (e.g. from url_generator's generate() with its
     *        default relative-path reference type) to send the user to once logged in — never a
     *        full URL, so a stray absolute link here could never become an open redirect.
     */
    public function generate(User $user, ?string $redirectPath = null, string $ttl = 'P14D'): string
    {
        $selector = bin2hex(random_bytes(self::SELECTOR_BYTES));
        $verifier = bin2hex(random_bytes(self::VERIFIER_BYTES));

        $link = new MagicLink();
        $link->setSelector($selector);
        $link->setHashedVerifier(hash('sha256', $verifier));
        $link->setUser($user);
        $link->setRedirectPath($redirectPath);
        $link->setExpiresAt((new \DateTimeImmutable())->add(new \DateInterval($ttl)));

        $this->em->persist($link);
        $this->em->flush();

        return $selector . $verifier;
    }

    /**
     * The MagicLink for {token}, if it's a real, unexpired token — or null otherwise. Doesn't mark
     * it used; call markUsed() once the caller has actually logged the user in.
     */
    public function resolve(string $token): ?MagicLink
    {
        if (strlen($token) !== (self::SELECTOR_BYTES + self::VERIFIER_BYTES) * 2) {
            return null;
        }

        $selector = substr($token, 0, self::SELECTOR_BYTES * 2);
        $verifier = substr($token, self::SELECTOR_BYTES * 2);

        $link = $this->magicLinkRepository->findOneBy(['selector' => $selector]);
        if (!$link || $link->isExpired()) {
            return null;
        }

        return hash_equals($link->getHashedVerifier(), hash('sha256', $verifier)) ? $link : null;
    }

    public function markUsed(MagicLink $link): void
    {
        $link->setLastUsedAt(new \DateTimeImmutable());
        $this->em->flush();
    }
}
