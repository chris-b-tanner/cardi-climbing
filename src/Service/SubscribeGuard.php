<?php

namespace App\Service;

use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;

/**
 * Spam protection for the public newsletter signup (SubscribeController), which otherwise only
 * has a CSRF token — and a bot gets one of those just by loading the page. Three cheap checks, no
 * third-party CAPTCHA:
 *
 *  - Honeypot: a `website` field hidden from people (templates/_subscribe_guard.html.twig) that
 *    form-filling bots complete.
 *  - Time trap: the form carries a signed "rendered at" timestamp. A person takes more than a few
 *    seconds to type their details; a script posting straight back doesn't. Signed with the
 *    kernel secret so it can't be forged, and expires so an old harvested token can't be replayed
 *    forever.
 *  - Rate limit: at most MAX_PER_HOUR signups per IP per hour (the app trusts Heroku's router, so
 *    getClientIp() is the visitor's address). Counted in cache.app, so it's per dyno and resets
 *    on deploy — fine for slowing a flood, not meant as an exact quota.
 *
 * check() says what tripped, so the controller can quietly pretend to succeed for the bot-only
 * signals (no hint to adapt to) but show a real message when a person is just rate-limited.
 */
class SubscribeGuard
{
    public const HONEYPOT_FIELD = 'website';
    public const TOKEN_FIELD    = '_form_ts';

    public const OK           = 'ok';
    public const BOT          = 'bot';
    public const RATE_LIMITED = 'rate_limited';

    private const MIN_SECONDS  = 3;
    private const MAX_SECONDS  = 7 * 24 * 3600;
    private const MAX_PER_HOUR = 5;

    public function __construct(
        #[Autowire('%kernel.secret%')] private readonly string $secret,
        #[Autowire(service: 'cache.app')] private readonly CacheItemPoolInterface $cache,
    ) {}

    /** The signed timestamp for a freshly rendered form. */
    public function issueToken(): string
    {
        $time = (string) time();

        return $time . '.' . $this->sign($time);
    }

    public function check(Request $request): string
    {
        if (trim((string) $request->request->get(self::HONEYPOT_FIELD, '')) !== '') {
            return self::BOT;
        }

        $parts = explode('.', (string) $request->request->get(self::TOKEN_FIELD, ''), 2);
        if (count($parts) !== 2 || !ctype_digit($parts[0]) || !hash_equals($this->sign($parts[0]), $parts[1])) {
            return self::BOT;
        }

        $age = time() - (int) $parts[0];
        if ($age < self::MIN_SECONDS || $age > self::MAX_SECONDS) {
            return self::BOT;
        }

        // Names carrying a link or markup are link-spam, never a real person's name.
        foreach (['firstName', 'lastName'] as $field) {
            if (preg_match('#https?:|www\.|[<>]|\.(?:com|ru|net|xyz|top)\b#i', (string) $request->request->get($field, ''))) {
                return self::BOT;
            }
        }

        // Fixed one-hour window per IP. Only reached by submissions that passed the checks above.
        $item = $this->cache->getItem('subscribe_ip_' . hash('sha256', (string) $request->getClientIp()));
        $count = $item->isHit() ? (int) $item->get() : 0;
        if ($count >= self::MAX_PER_HOUR) {
            return self::RATE_LIMITED;
        }
        $item->set($count + 1);
        if (!$item->isHit()) {
            $item->expiresAfter(3600);
        }
        $this->cache->save($item);

        return self::OK;
    }

    private function sign(string $value): string
    {
        return hash_hmac('sha256', 'subscribe|' . $value, $this->secret);
    }
}
