<?php

namespace App\Controller\Concern;

/** For controllers that redirect to a user-supplied path (a `returnTo` field, a stored redirect path). */
trait SafeLocalRedirectTrait
{
    /** {candidate} if it's a local path on this site, otherwise {fallback} — never a full or protocol-relative URL, so a user-supplied target can't become an open redirect. */
    private function localPathOr(mixed $candidate, string $fallback): string
    {
        return (is_string($candidate) && str_starts_with($candidate, '/') && !str_starts_with($candidate, '//'))
            ? $candidate
            : $fallback;
    }
}
