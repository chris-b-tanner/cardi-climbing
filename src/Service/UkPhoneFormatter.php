<?php

namespace App\Service;

use libphonenumber\NumberParseException;
use libphonenumber\PhoneNumber;
use libphonenumber\PhoneNumberFormat;
use libphonenumber\PhoneNumberUtil;

/**
 * Formats phone numbers for display using the UK national numbering plan (mobile 07xxx xxxxxx,
 * London 020 xxxx xxxx, other geographic/non-geographic groupings, etc.) — every contact in this
 * system is assumed to be a UK number, so there's no need to detect or format international ones.
 * Used both from Twig (see AppExtension's uk_phone/tel_link filters) and from plain PHP contexts
 * like the members CSV export, so the same formatting applies wherever a phone number is shown.
 */
class UkPhoneFormatter
{
    /**
     * The UK national-format grouping for {raw} (e.g. "07718 991320", "020 7946 0958") — or {raw}
     * unchanged if it doesn't parse as a valid UK number, so this never mangles a partial, foreign,
     * or junk value already on file.
     */
    public function format(?string $raw): ?string
    {
        $number = $this->parse($raw);
        return $number ? PhoneNumberUtil::getInstance()->format($number, PhoneNumberFormat::NATIONAL) : $raw;
    }

    /** {raw} as a dialable value for a `tel:` link (E.164, e.g. "+447718991320") — or {raw} unchanged if it doesn't parse as a valid UK number. */
    public function dialable(?string $raw): ?string
    {
        $number = $this->parse($raw);
        return $number ? PhoneNumberUtil::getInstance()->format($number, PhoneNumberFormat::E164) : $raw;
    }

    private function parse(?string $raw): ?PhoneNumber
    {
        if ($raw === null || trim($raw) === '') {
            return null;
        }

        $util = PhoneNumberUtil::getInstance();

        try {
            $number = $util->parse($raw, 'GB');
        } catch (NumberParseException) {
            return null;
        }

        return $util->isValidNumber($number) ? $number : null;
    }
}
