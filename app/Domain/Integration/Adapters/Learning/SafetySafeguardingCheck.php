<?php

namespace App\Domain\Integration\Adapters\Learning;

use App\Domain\Learning\Contracts\Seams\SafeguardingCheck;
use App\Domain\Learning\Models\Bundle;
use App\Domain\Learning\Models\Course;
use App\Domain\Learning\Models\LibraryResource;
use App\Domain\Learning\Support\GateDecision;
use App\Domain\Safety\Contracts\SafeguardingGate;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * The safety gate for the learning domain's three questions:
 *  - publish: a course or resource is public; it is commercial when it has a price. A child's pre-moderated
 *    publish is refused here because learning has no pre-moderation queue (said in the reason).
 *  - pay: the gate's payment rule; a guardian requirement is honoured by money (the order is held).
 *  - assign_work: a tutor sending work to a learner is contact; asked as a message inside a teaching context.
 */
class SafetySafeguardingCheck implements SafeguardingCheck
{
    public function __construct(private readonly SafeguardingGate $gate) {}

    public function allows(User $actor, string $ability, ?Model $subject = null): GateDecision
    {
        $decision = match ($ability) {
            'publish' => $this->gate->allows($actor, 'publish', $subject, ['audience' => 'public', 'commercial' => $this->isCommercial($actor, $subject)]),
            'pay' => $this->gate->allows($actor, 'pay', $subject, ['kind' => 'purchase']),
            'assign_work' => $this->gate->allows($actor, 'message', $subject instanceof User ? $subject : null, ['context_type' => 'booking']),
            default => $this->gate->allows($actor, $ability, $subject),
        };

        if ($decision->denied()) {
            return GateDecision::deny($decision->message());
        }

        if ($ability === 'publish' && $decision->requires('moderation:pre')) {
            return GateDecision::deny('Learners under 13 cannot publish here: courses and library material have no pre-moderation queue yet.');
        }

        return GateDecision::allow();
    }

    /** Selling is publishing your own priced item; a review of someone else's paid course is not. */
    private function isCommercial(User $actor, ?Model $subject): bool
    {
        return match (true) {
            $subject instanceof Course => $subject->isOwnedBy($actor) && ! $subject->isFree(),
            $subject instanceof LibraryResource => $subject->isOwnedBy($actor) && ! (bool) $subject->is_free,
            $subject instanceof Bundle => (int) $subject->owner_id === $actor->id,
            default => false,
        };
    }
}
