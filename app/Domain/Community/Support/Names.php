<?php

namespace App\Domain\Community\Support;

use App\Models\User;

/** People appear in rooms by first name and last initial ("Thandi M."): enough to talk, minimal for minors (POPIA). */
final class Names
{
    public static function short(?User $user): string
    {
        if ($user === null) {
            return 'Former member';
        }

        $parts = preg_split('/\s+/u', trim((string) $user->name)) ?: [];
        $first = $parts[0] ?? 'Member';
        $last = count($parts) > 1 ? mb_substr((string) end($parts), 0, 1).'.' : '';

        return trim($first.' '.$last);
    }
}
