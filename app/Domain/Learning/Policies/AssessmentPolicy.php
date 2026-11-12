<?php

namespace App\Domain\Learning\Policies;

use App\Domain\Learning\Models\Assessment;
use App\Models\User;

class AssessmentPolicy
{
    public function manage(User $user, Assessment $assessment): bool
    {
        return $assessment->owner_id === $user->id;
    }
}
