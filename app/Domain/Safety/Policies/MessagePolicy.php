<?php

namespace App\Domain\Safety\Policies;

use App\Domain\Safety\Models\Message;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

class MessagePolicy
{
    public function view(User $user, Message $message): bool
    {
        if ($message->isHidden() && ! $user->is_platform_operator) {
            return false;
        }

        return Gate::forUser($user)->allows('view', $message->conversation);
    }

    /** Anyone who can read a message can report it, including a guardian. */
    public function report(User $user, Message $message): bool
    {
        return Gate::forUser($user)->allows('view', $message->conversation);
    }
}
