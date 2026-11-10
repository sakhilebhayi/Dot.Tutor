<?php

namespace App\Domain\Safety\Services;

use App\Domain\Safety\Contracts\AgeGatedSpace;
use App\Domain\Safety\Contracts\GuardianApprovals;
use App\Domain\Safety\Contracts\InvolvesPeople;
use App\Domain\Safety\Contracts\SafeguardingGate;
use App\Domain\Safety\Enums\AgeBand;
use App\Domain\Safety\Models\Conversation;
use App\Domain\Safety\Models\ConversationParticipant;
use App\Domain\Safety\Support\Decision;
use App\Models\TutorProfile;
use App\Models\TutorSession;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * The safeguarding rules, in one place (docs/R47_SAFETY.md section 3 lists each with its test).
 */
class SafeguardingRules implements SafeguardingGate
{
    public function __construct(
        private readonly AccountStanding $standing,
        private readonly Guardians $guardians,
        private readonly Screening $screening,
        private readonly GuardianApprovals $approvals,
    ) {}

    public function allows(?User $actor, string $ability, mixed $subject = null, array $context = []): Decision
    {
        if (! in_array($ability, self::ABILITIES, true)) {
            throw new InvalidArgumentException("Unknown safeguarding ability [$ability].");
        }

        if ($actor === null) {
            return Decision::deny('not_signed_in', 'Sign in first.');
        }

        if ($this->standing->isSuspended($actor)) {
            return Decision::deny('account_suspended', 'This account is suspended. Contact support if you think this is a mistake.');
        }

        return match ($ability) {
            'book' => $this->book($actor, $subject),
            'pay' => $this->pay($actor, $subject, $context),
            'message' => $this->message($actor, $subject, $context),
            'join_room' => $this->joinRoom($actor, $subject),
            'meet_live' => $this->meetLive($actor, $subject, $context),
            'publish' => $this->publish($actor, $context),
            'view_contact' => $this->viewContact($actor, $subject),
            'upload' => $this->upload($actor, $subject),
        };
    }

    private function book(User $actor, mixed $subject): Decision
    {
        if ($limited = $this->limited($actor)) {
            return $limited;
        }

        $people = [...$this->peopleIn($subject, $actor), $actor];
        $decision = $this->screeningFor($people);

        if ($decision->denied()) {
            return $decision;
        }

        foreach ($people as $person) {
            if ($this->standing->isMinor($person) && $this->approvals->requires($person, 'booking')
                && ! ($subject instanceof Model && $subject->exists && $this->approvals->isApproved($person, 'booking', $subject))) {
                $decision = $decision->requiring('guardian_approval:booking', 'A parent or guardian must approve this booking before it is confirmed.');
            }
        }

        return $decision;
    }

    /** @param array<string, mixed> $context */
    private function pay(User $actor, mixed $subject, array $context): Decision
    {
        if ($limited = $this->limited($actor)) {
            return $limited;
        }

        $kind = ($context['kind'] ?? 'purchase') === 'paid_room' ? 'paid_room' : 'purchase';

        if ($this->standing->isMinor($actor) && $this->approvals->requires($actor, $kind)
            && ! ($subject instanceof Model && $this->approvals->isApproved($actor, $kind, $subject))) {
            return Decision::allow()->requiring("guardian_approval:$kind", 'A parent or guardian must approve this payment first.');
        }

        return Decision::allow();
    }

    /** @param array<string, mixed> $context */
    private function message(User $actor, mixed $subject, array $context): Decision
    {
        if ($subject instanceof Conversation) {
            $participant = $subject->participantFor($actor);

            if ($participant === null) {
                return Decision::deny('not_a_participant', 'You are not part of this conversation.');
            }

            if ($subject->closed_at !== null) {
                return Decision::deny('conversation_closed', 'This conversation is closed.');
            }

            $contextType = $subject->context_type;
            $people = User::query()->whereIn('id', $subject->memberIds())->get()->all();

            // A guardian or operator speaks as an overseer, not as a party to the context.
            if ($participant->role !== ConversationParticipant::MEMBER) {
                return Decision::allow();
            }
        } else {
            $contextType = (string) ($context['context_type'] ?? Conversation::DIRECT);
            $people = $this->peopleIn($subject, $actor);
            $people[] = $actor;
        }

        if ($contextType === Conversation::SUPPORT) {
            return Decision::allow();
        }

        if ($limited = $this->limited($actor)) {
            return $limited;
        }

        $minors = array_filter($people, fn (User $p) => $this->standing->isMinor($p));
        $adults = array_filter($people, fn (User $p) => ! $this->standing->isMinor($p));
        $restricted = (array) config('safety.messaging.context_required_for', ['child', 'teen']);

        if ($contextType === Conversation::DIRECT && $minors !== []) {
            $bands = array_map(fn (User $p) => $this->standing->band($p)->value, $minors);

            if ($adults !== [] && array_intersect($bands, $restricted) !== []) {
                return Decision::deny('minor_needs_context', 'Messages between an adult and a learner under 18 happen only inside a booking, a room or support.');
            }

            if (in_array(AgeBand::Child->value, $bands, true)) {
                return Decision::deny('minor_needs_context', 'Learners under 13 message only inside a booking, a room or support.');
            }
        }

        return $this->screeningFor($people);
    }

    private function joinRoom(User $actor, mixed $space): Decision
    {
        if (! $space instanceof AgeGatedSpace) {
            throw new InvalidArgumentException('join_room needs an AgeGatedSpace subject.');
        }

        if ($limited = $this->limited($actor)) {
            return $limited;
        }

        $band = $this->standing->band($actor);
        $bands = $space->safetyAgeBands();
        $hasMinorBands = array_filter($bands, fn (AgeBand $b) => $b->isMinor()) !== [];

        if ($band->isMinor() && ! in_array($band, $bands, true)) {
            return Decision::deny('age_band_not_allowed', 'This room is for a different age group.');
        }

        if (! $band->isMinor() && $hasMinorBands && ! $this->standing->isOperator($actor)) {
            if (! $this->screening->isClearedForMinors($actor)) {
                return Decision::deny('adult_screening_missing', 'Adults join rooms with learners under 18 only after a safeguarding screening.');
            }
        }

        if ($band->isMinor() && $space->safetyIsPaid() && $this->approvals->requires($actor, 'paid_room')
            && ! ($space instanceof Model && $this->approvals->isApproved($actor, 'paid_room', $space))) {
            return Decision::allow()->requiring('guardian_approval:paid_room', 'A parent or guardian must approve joining a paid room.');
        }

        return Decision::allow();
    }

    /** @param array<string, mixed> $context */
    private function meetLive(User $actor, mixed $subject, array $context): Decision
    {
        if ($limited = $this->limited($actor)) {
            return $limited;
        }

        $people = $this->peopleIn($subject, $actor);
        $people[] = $actor;
        $people = $this->unique($people);

        $decision = $this->screeningFor($people);

        if ($decision->denied()) {
            return $decision;
        }

        $minors = array_filter($people, fn (User $p) => $this->standing->isMinor($p));
        $oneToOne = count($people) === 2;

        foreach ($minors as $minor) {
            $needsApproval = ($oneToOne && $this->standing->band($minor) === AgeBand::Child)
                || $this->approvals->requires($minor, 'live_lesson');

            if ($needsApproval && ! ($subject instanceof Model && $this->approvals->isApproved($minor, 'live_lesson', $subject))) {
                return Decision::deny('guardian_approval_missing', 'A parent or guardian must approve this live lesson first.');
            }

            if (! empty($context['record']) && ! $this->guardians->hasRecordingConsent($minor)) {
                return Decision::deny('recording_consent_missing', 'This lesson cannot be recorded: a parent or guardian has not agreed to recording.');
            }
        }

        return Decision::allow();
    }

    /** @param array<string, mixed> $context */
    private function publish(User $actor, array $context): Decision
    {
        if ($limited = $this->limited($actor)) {
            return $limited;
        }

        $band = $this->standing->band($actor);

        if ($band->isMinor() && ! empty($context['commercial'])) {
            return Decision::deny('minor_cannot_sell', 'Selling courses, materials or lessons is for people 18 or older.');
        }

        if ($band === AgeBand::Child && ($context['audience'] ?? 'public') === 'public') {
            return Decision::deny('child_public_publish', 'Learners under 13 share inside their own learning rooms only.');
        }

        if ($band === AgeBand::Child) {
            return Decision::allow()->requiring('moderation:pre', 'A moderator checks posts from learners under 13 before others see them.');
        }

        return Decision::allow();
    }

    private function viewContact(User $actor, mixed $subject): Decision
    {
        $owner = $subject instanceof TutorProfile ? $subject->user : $subject;

        if (! $owner instanceof User) {
            throw new InvalidArgumentException('view_contact needs a User or TutorProfile subject.');
        }

        if ($owner->is($actor) || $this->standing->isOperator($actor)) {
            return Decision::allow();
        }

        if ($this->standing->isMinor($owner) && $this->guardians->isGuardianOf($actor, $owner)) {
            return Decision::allow();
        }

        if ($this->standing->isMinor($actor) && $this->guardians->isGuardianOf($owner, $actor)) {
            return Decision::allow();
        }

        if ($this->standing->isMinor($owner) || $this->standing->isMinor($actor)) {
            return Decision::deny('contact_hidden_minor', 'Contact details are hidden when a learner under 18 is involved.');
        }

        return Decision::allow();
    }

    private function upload(User $actor, mixed $subject): Decision
    {
        if ($subject instanceof Conversation) {
            return $this->message($actor, $subject, []);
        }

        if ($limited = $this->limited($actor)) {
            return $limited;
        }

        return Decision::allow();
    }

    private function limited(User $actor): ?Decision
    {
        if ($this->standing->isLimited($actor)) {
            return Decision::deny('guardian_consent_required', 'A parent or guardian needs to link to this account and give consent first.');
        }

        return null;
    }

    /**
     * Every adult in an exchange with a minor must hold a current screening, unless they are an operator or a
     * guardian of one of the minors.
     *
     * @param  list<User>  $people
     */
    private function screeningFor(array $people): Decision
    {
        $people = $this->unique($people);
        $minors = array_values(array_filter($people, fn (User $p) => $this->standing->isMinor($p)));

        if ($minors === []) {
            return Decision::allow();
        }

        foreach ($people as $person) {
            if ($this->standing->isMinor($person) || $this->standing->isOperator($person)) {
                continue;
            }

            $isGuardian = collect($minors)->contains(fn (User $minor) => $this->guardians->isGuardianOf($person, $minor));

            if (! $isGuardian && ! $this->screening->isClearedForMinors($person)) {
                return Decision::deny('tutor_screening_missing', 'This tutor has not completed the safeguarding screening needed to work with learners under 18.');
            }
        }

        return Decision::allow();
    }

    /** @return list<User> the people in a subject other than the actor */
    private function peopleIn(mixed $subject, User $actor): array
    {
        $ids = match (true) {
            $subject instanceof User => [$subject->id],
            $subject instanceof TutorProfile => [$subject->user_id],
            $subject instanceof TutorSession => [$subject->student_id, $subject->tutorProfile?->user_id],
            $subject instanceof Conversation => $subject->memberIds(),
            $subject instanceof InvolvesPeople => $subject->safetyParticipantIds(),
            $subject instanceof AgeGatedSpace => $subject->safetyMemberIds(),
            default => [],
        };

        $ids = array_values(array_unique(array_filter($ids, fn ($id) => $id !== null && (int) $id !== $actor->id)));

        return $ids === [] ? [] : User::query()->whereIn('id', $ids)->orderBy('id')->get()->all();
    }

    /**
     * @param  list<User>  $people
     * @return list<User>
     */
    private function unique(array $people): array
    {
        $seen = [];

        foreach ($people as $person) {
            $seen[$person->id] = $person;
        }

        return array_values($seen);
    }
}
