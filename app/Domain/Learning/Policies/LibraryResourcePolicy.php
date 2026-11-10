<?php

namespace App\Domain\Learning\Policies;

use App\Domain\Learning\Models\LibraryResource;
use App\Models\User;

class LibraryResourcePolicy
{
    /** Reviewers are platform operators until the ops agent adds the verification_reviewer role; never the owner. */
    public function review(User $user, LibraryResource $resource): bool
    {
        return (bool) $user->is_platform_operator && $resource->owner_id !== $user->id;
    }

    public function withdraw(User $user, LibraryResource $resource): bool
    {
        return $resource->owner_id === $user->id;
    }
}
