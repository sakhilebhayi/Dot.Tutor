<?php

namespace App\Domain\Ai\Guardrails;

use App\Domain\Safety\Services\AccountStanding;
use App\Models\User;

/**
 * Who may use the assistant at all (server side, before anything is built or sent):
 * - suspended accounts: no;
 * - a known minor without a linked, consenting guardian: no. Sending a child's words to an outside AI service is
 *   processing a child's personal information (POPIA s35: consent of a competent person), D-907;
 * - bands switched off in config ai.bands_enabled: no;
 * - tutor tools: only people with a tutor profile.
 */
class Eligibility
{
    public function __construct(private readonly AccountStanding $standing) {}

    public function check(User $user, LearnerContext $context, string $role = 'learner'): ?Refusal
    {
        if ($this->standing->isSuspended($user)) {
            return new Refusal('account_suspended');
        }
        // Safety's own rule (a known minor, or anyone with a guardian link, without a consenting guardian). Someone
        // whose age is unknown is not a known minor: they are answered with the child guardrails instead (D-905).
        if ($this->standing->isLimited($user)) {
            return new Refusal('guardian_consent_required');
        }
        if (! (bool) config('ai.bands_enabled.'.$context->band, true)) {
            return new Refusal('band_disabled');
        }
        if ($role === 'tutor' && ! $user->tutorProfile()->exists()) {
            return new Refusal('not_a_tutor');
        }

        return null;
    }
}
