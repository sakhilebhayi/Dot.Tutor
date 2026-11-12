<?php

namespace App\Domain\Classroom\Seams;

use App\Domain\Classroom\Contracts\SummaryDrafter;
use App\Domain\Classroom\Models\Classroom;

/** No AI summary drafting is connected. The after-lesson page says so and the tutor writes the summary. */
class NullSummaryDrafter implements SummaryDrafter
{
    public function available(): bool
    {
        return false;
    }

    public function draft(Classroom $classroom): ?string
    {
        return null;
    }
}
