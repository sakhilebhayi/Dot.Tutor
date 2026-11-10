<?php

namespace App\Domain\Money\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Refund extends Model
{
    public const REQUESTED = 'requested';

    public const PENDING = 'pending';

    public const PROCESSING = 'processing';

    public const PROCESSED = 'processed';

    public const FAILED = 'failed';

    public const OPEN = [self::REQUESTED, self::PENDING, self::PROCESSING];

    /** @var array<string, string> */
    public const REASONS = [
        'lesson_cancelled' => 'Lesson cancelled',
        'lesson_not_delivered' => 'Lesson not delivered',
        'quality_complaint' => 'Quality complaint upheld',
        'course_refund_window' => 'Refund within the refund window',
        'duplicate_payment' => 'Duplicate payment',
        'guardian_request' => 'Guardian request',
        'goodwill' => 'Goodwill',
        'other' => 'Other',
    ];

    protected $table = 'money_refunds';

    protected $guarded = ['id'];

    protected $casts = [
        'amount_minor' => 'integer',
        'processed_at' => 'datetime',
    ];

    /** @return BelongsTo<Order, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /** @return BelongsTo<OrderLine, $this> */
    public function line(): BelongsTo
    {
        return $this->belongsTo(OrderLine::class, 'order_line_id');
    }

    /** @return BelongsTo<Payment, $this> */
    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    /** @return BelongsTo<User, $this> */
    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function isOpen(): bool
    {
        return in_array($this->status, self::OPEN, true);
    }
}
