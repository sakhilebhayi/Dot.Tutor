<?php

namespace App\Domain\Marketplace\Enums;

/** A learner's seat on a group session. */
enum SeatStatus: string
{
    case AwaitingGuardian = 'awaiting_guardian';
    case AwaitingPayment = 'awaiting_payment';
    case Confirmed = 'confirmed';
    case Cancelled = 'cancelled';
    case Attended = 'attended';
    case NoShow = 'no_show';

    /**
     * Seats that count against capacity.
     *
     * @return list<string>
     */
    public static function holding(): array
    {
        return [self::AwaitingGuardian->value, self::AwaitingPayment->value, self::Confirmed->value];
    }
}
