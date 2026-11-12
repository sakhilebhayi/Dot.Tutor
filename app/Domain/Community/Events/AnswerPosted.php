<?php

namespace App\Domain\Community\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * An answer under a question became visible to the room: posted by someone whose posts are not pre-moderated, or
 * released by a moderator. Never raised for a held or hidden answer. Community sends no notifications itself: the
 * comms domain listens (tells the question's author).
 */
final class AnswerPosted implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(public readonly int $answerId) {}
}
