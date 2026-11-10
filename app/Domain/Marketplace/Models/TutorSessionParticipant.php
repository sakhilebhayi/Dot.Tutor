<?php

namespace App\Domain\Marketplace\Models;

use App\Models\TutorSession;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A learner's seat on a group session. */
class TutorSessionParticipant extends Model
{
    protected $fillable = [
        'tutor_session_id', 'learner_id', 'status', 'price_minor', 'currency', 'payment_status', 'order_reference',
        'guardian_status', 'tutor_package_purchase_id', 'hold_expires_at', 'cancelled_at',
    ];

    protected $casts = [
        'price_minor' => 'integer',
        'hold_expires_at' => 'datetime',
        'cancelled_at' => 'datetime',
    ];

    /** @return BelongsTo<TutorSession, $this> */
    public function session(): BelongsTo
    {
        return $this->belongsTo(TutorSession::class, 'tutor_session_id');
    }

    /** @return BelongsTo<User, $this> */
    public function learner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'learner_id');
    }
}
