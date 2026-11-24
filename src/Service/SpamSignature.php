<?php

namespace App\Service;

/**
 * Details a sign-up bot keeps submitting on the public forms (registration and the newsletter
 * subscribe form): name "Test User", company "Test Company", phone 8 (999) 000-00-00. One list, so
 * a new pattern blocks it everywhere at once. Comparison ignores case and extra spaces, and the
 * phone is compared as digits only so any formatting of it matches.
 */
final class SpamSignature
{
    private const PHONE_DIGITS = ['89990000000'];
    private const FULL_NAMES   = ['test user'];
    private const COMPANIES    = ['test company'];

    public static function matches(string $firstName, string $lastName, string $company = '', string $phone = ''): bool
    {
        $phoneDigits = preg_replace('/\D+/', '', $phone);

        return in_array(self::normalise($firstName . ' ' . $lastName), self::FULL_NAMES, true)
            || in_array(self::normalise($company), self::COMPANIES, true)
            || ($phoneDigits !== '' && in_array($phoneDigits, self::PHONE_DIGITS, true));
    }

    private static function normalise(string $value): string
    {
        return strtolower(preg_replace('/\s+/', ' ', trim($value)));
    }
}
