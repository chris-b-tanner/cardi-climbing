<?php

namespace App\Service\Mailer;

use App\Entity\User;

/**
 * Replaces admin-typed placeholders in an outbound email body with the actual recipient's data.
 * Introduced when the automatic "Hi {firstname}," greeting was removed from the blank email layout
 * (email/bulk_blank.*.twig) — personalising a message is now something the admin opts into by
 * typing the placeholder wherever they want it, rather than something every blank-layout email got
 * whether it fit the wording or not.
 */
class EmailPlaceholders
{
    private const FIRST_NAME = '_firstName_';

    /** {body} with every `_firstName_` occurrence replaced by {user}'s first name (or removed entirely if they don't have one on file). */
    public function apply(string $body, User $user): string
    {
        return str_replace(self::FIRST_NAME, $user->getFirstName() ?? '', $body);
    }
}
