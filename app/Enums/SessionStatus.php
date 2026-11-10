<?php

namespace App\Enums;

/**
 * Every status a tutor_sessions row can hold. The column is a plain string
 * (it was widened from a native enum in 2026-08), so this is the only place
 * the vocabulary is declared.
 *
 * R-47 booking v2 (decision D-310): the state the brief calls "requested" is
 * stored as the existing value 'pending', so no historic row is rewritten and
 * every existing caller keeps working. The API reports it as "requested"
 * (see state()). The transitions between states live in
 * App\Domain\Marketplace\Services\BookingStateMachine.
 */
enum SessionStatus: string
{
    case Pending = 'pending';
    case AwaitingGuardian = 'awaiting_guardian';
    case AwaitingPayment = 'awaiting_payment';
    case Confirmed = 'confirmed';
    case InProgress = 'in_progress';
    case Completed = 'completed';
    case Cancelled = 'cancelled';
    case NoShow = 'no_show';
    case Disputed = 'disputed';

    /** The brief's name for a request waiting on the tutor. */
    public const Requested = self::Pending;

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $s) => $s->value, self::cases());
    }

    /**
     * Statuses that occupy the tutor's and the student's calendar: a pending
     * request holds the slot so two students cannot ask for the same hour, and
     * so do requests waiting on a guardian or on payment, and a lesson that is
     * under way.
     *
     * @return list<string>
     */
    public static function holdingTime(): array
    {
        return [
            self::Pending->value,
            self::AwaitingGuardian->value,
            self::AwaitingPayment->value,
            self::Confirmed->value,
            self::InProgress->value,
        ];
    }

    /** The public name of this state in the JSON API ("requested" for pending). */
    public function state(): string
    {
        return $this === self::Pending ? 'requested' : $this->value;
    }

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Requested, waiting for the tutor',
            self::AwaitingGuardian => 'Waiting for a guardian to approve',
            self::AwaitingPayment => 'Accepted, waiting for payment',
            self::Confirmed => 'Confirmed',
            self::InProgress => 'In progress',
            self::Completed => 'Completed',
            self::Cancelled => 'Cancelled',
            self::NoShow => 'No-show',
            self::Disputed => 'Disputed',
        };
    }
}
