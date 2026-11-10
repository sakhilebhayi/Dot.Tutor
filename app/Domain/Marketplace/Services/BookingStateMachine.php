<?php

namespace App\Domain\Marketplace\Services;

use App\Domain\Marketplace\Exceptions\MarketplaceException;
use App\Domain\Marketplace\Models\TutorSessionEvent;
use App\Enums\SessionStatus;
use App\Models\TutorSession;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * The booking state machine (brief: requested, awaiting_guardian,
 * awaiting_payment, confirmed, in_progress, completed, cancelled, no_show,
 * disputed). Every change happens under a row lock on the session, checks the
 * transition is allowed from the state the row is ACTUALLY in (not the state
 * a stale caller saw), and appends an event. "requested" is stored as
 * 'pending' (decision D-310).
 */
class BookingStateMachine
{
    /** @var array<string, list<string>> */
    public const TRANSITIONS = [
        'awaiting_guardian' => ['pending', 'cancelled'],
        'pending' => ['awaiting_payment', 'confirmed', 'cancelled', 'no_show'],
        'awaiting_payment' => ['confirmed', 'cancelled'],
        'confirmed' => ['in_progress', 'completed', 'cancelled', 'no_show'],
        'in_progress' => ['completed', 'disputed'],
        'completed' => ['disputed'],
        'no_show' => ['disputed'],
        'disputed' => ['completed', 'cancelled'],
        'cancelled' => [],
    ];

    public function allowed(string $from, SessionStatus $to): bool
    {
        return in_array($to->value, self::TRANSITIONS[$from] ?? [], true);
    }

    /**
     * Move a session to $to and record why. $guard runs on the locked row and
     * may throw to refuse (time rules, ownership re-checks).
     *
     * @param  array<string, mixed>  $changes  extra columns to write
     * @param  array<string, mixed>  $context  stored on the event
     * @param  (callable(TutorSession): void)|null  $guard
     * @param  (callable(TutorSession): void)|null  $after  runs inside the transaction after the update
     */
    public function transition(
        TutorSession $session,
        SessionStatus $to,
        ?User $actor,
        string $event,
        ?string $reason = null,
        array $changes = [],
        array $context = [],
        ?callable $guard = null,
        ?callable $after = null,
    ): TutorSession {
        return DB::transaction(function () use ($session, $to, $actor, $event, $reason, $changes, $context, $guard, $after) {
            $locked = TutorSession::query()->whereKey($session->id)->lockForUpdate()->firstOrFail();
            $from = $locked->status;

            if (! $this->allowed($from, $to)) {
                throw new MarketplaceException(
                    'invalid_transition',
                    sprintf('This booking is %s, so it cannot become %s.', SessionStatus::from($from)->state(), $to->state()),
                    409,
                );
            }
            if ($guard !== null) {
                $guard($locked);
            }

            $locked->forceFill([...$changes, 'status' => $to->value])->save();
            $this->record($locked, $event, $actor, $from, $to->value, $reason, $context);

            if ($after !== null) {
                $after($locked);
            }

            return $locked;
        });
    }

    /** @param  array<string, mixed>  $context */
    public function record(TutorSession $session, string $event, ?User $actor, ?string $from, ?string $to, ?string $reason = null, array $context = [], ?int $participantId = null): TutorSessionEvent
    {
        return TutorSessionEvent::create([
            'tutor_session_id' => $session->id,
            'participant_id' => $participantId,
            'actor_id' => $actor?->id,
            'event' => $event,
            'from_state' => $from,
            'to_state' => $to,
            'reason' => $reason,
            'context' => $context === [] ? null : $context,
        ]);
    }
}
