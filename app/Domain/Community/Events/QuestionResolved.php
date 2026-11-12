<?php

namespace App\Domain\Community\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A question was resolved: an answer was accepted (acceptedAnswerId), or the asker or a tutor marked it resolved
 * without one. The comms domain listens (tells the accepted answer's author, and the asker when someone else
 * resolved it).
 */
final class QuestionResolved implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public readonly int $questionId,
        public readonly int $actorId,
        public readonly ?int $acceptedAnswerId = null,
    ) {}
}
