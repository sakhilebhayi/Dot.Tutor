<?php

namespace App\Domain\Learning\Contracts\Seams;

use App\Models\User;

/** The learning side of Money\Contracts\EntitlementCheck: has this user paid for (or been given) this item? */
interface EntitlementLookup
{
    /** @param  string  $type  course | bundle | resource */
    public function owns(User $user, string $type, int $id): bool;
}
