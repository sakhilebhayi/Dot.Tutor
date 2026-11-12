<?php

namespace App\Domain\Classroom\Contracts;

use App\Domain\Classroom\Models\Classroom;

/**
 * A seam for the AI agent: draft a post-lesson summary from what is stored about the lesson (shared notes, chat,
 * the tutor's notes). A draft is only ever shown to the tutor, labelled as an AI draft, and published only after the
 * tutor edits or accepts it. The default returns null: no AI draft exists and the screen says so.
 */
interface SummaryDrafter
{
    public function available(): bool;

    public function draft(Classroom $classroom): ?string;
}
