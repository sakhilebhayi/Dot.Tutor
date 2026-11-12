<?php

namespace App\Domain\Learning\Seams;

use App\Domain\Learning\Contracts\Seams\EntitlementLookup;
use App\Models\User;

/** Default until the money domain merges: nobody owns paid content, so only free content is reachable. */
class NullEntitlementLookup implements EntitlementLookup
{
    public function owns(User $user, string $type, int $id): bool
    {
        return false;
    }
}
