<?php

namespace App\Domain\Safety\Policies;

use App\Domain\Safety\Models\Conversation;
use App\Domain\Safety\Models\Message;
use App\Domain\Safety\Models\Report;
use App\Models\User;

class ConversationPolicy
{
    /**
     * Participants (members, a minor's guardians, operators who joined a support thread) read the conversation.
     * Other operators read it only when it is a support thread or something in it was reported.
     */
    public function view(User $user, Conversation $conversation): bool
    {
        if ($conversation->participantFor($user) !== null) {
            return true;
        }

        return $user->is_platform_operator && ($conversation->context_type === Conversation::SUPPORT || $this->wasReported($conversation));
    }

    private function wasReported(Conversation $conversation): bool
    {
        return Report::query()->where('reportable_type', (new Message)->getMorphClass())
            ->whereIn('reportable_id', $conversation->messages()->select('id'))->exists();
    }
}
