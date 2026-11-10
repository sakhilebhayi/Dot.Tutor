<?php

namespace App\Domain\Comms\Support;

use App\Models\User;

/** Which signed-in shell a person's notification pages sit in: operations, the tutor workspace, or learning. */
final class ShellFor
{
    public static function user(User $user): string
    {
        return match (true) {
            (bool) $user->is_platform_operator => 'operations',
            $user->tutorProfile()->exists() => 'workspace',
            default => 'learner',
        };
    }
}
