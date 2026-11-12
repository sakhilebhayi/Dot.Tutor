<?php

namespace App\Support;

use App\Domain\Academic\Models\LearnerProfile;
use App\Domain\Safety\Models\GuardianLink;
use App\Models\User;
use Illuminate\Support\Facades\Route;

/**
 * Where a person lands after signing in (password, two-factor or InfoDot), when they did not ask for a page.
 * One person can hold several roles; the first that applies wins (D-1801):
 *  1. operator (`is_platform_operator`) -> /ops
 *  2. tutor (any tutor profile, a draft included: they are building a business) -> /teach
 *  3. guardian (an active link to a learner) who has no learning profile of their own -> /guardian
 *  4. everyone else is a learner -> /learn
 * A route that is not registered falls through to the next, and finally to the legacy dashboard.
 */
final class RoleHome
{
    public function for(?User $user): string
    {
        foreach ($this->candidates($user) as $name) {
            if (Route::has($name)) {
                return route($name, absolute: false);
            }
        }

        return route('dashboard', absolute: false);
    }

    /** @return list<string> */
    private function candidates(?User $user): array
    {
        if ($user === null) {
            return ['learn.home'];
        }

        $names = [];
        if ($user->is_platform_operator) {
            $names[] = 'ops.home';
        }
        if ($user->tutorProfile()->exists()) {
            $names[] = 'teach.home';
        }
        if ($this->isGuardianOnly($user)) {
            $names[] = 'guardian.home';
        }
        $names[] = 'learn.home';

        return $names;
    }

    private function isGuardianOnly(User $user): bool
    {
        if (! class_exists(GuardianLink::class) || ! GuardianLink::query()->where('guardian_id', $user->id)->active()->exists()) {
            return false;
        }

        return ! class_exists(LearnerProfile::class) || ! LearnerProfile::query()->where('user_id', $user->id)->exists();
    }
}
