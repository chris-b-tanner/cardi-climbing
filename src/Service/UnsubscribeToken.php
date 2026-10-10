<?php

namespace App\Service;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/** The signed token in every unsubscribe link — generated for outgoing email (AppExtension::unsubscribeUrl()) and checked by UnsubscribeController, so both always agree on how it's derived. */
class UnsubscribeToken
{
    public function __construct(
        #[Autowire('%kernel.secret%')] private readonly string $appSecret,
    ) {}

    public static function normaliseEmail(string $email): string
    {
        return strtolower(trim($email));
    }

    public function generate(string $email): string
    {
        return hash_hmac('sha256', self::normaliseEmail($email), $this->appSecret);
    }

    public function isValid(string $email, string $token): bool
    {
        return self::normaliseEmail($email) !== '' && hash_equals($this->generate($email), $token);
    }
}
