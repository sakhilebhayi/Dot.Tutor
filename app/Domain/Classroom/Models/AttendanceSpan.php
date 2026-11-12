<?php

namespace App\Domain\Classroom\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One stretch of real presence in the room: opened when the person joins, extended by every poll their browser makes,
 * closed when they leave, are removed, stop answering or the room ends. Attendance is the sum of these spans.
 */
class AttendanceSpan extends Model
{
    protected $table = 'classroom_attendance';

    protected $fillable = ['classroom_id', 'user_id', 'role', 'joined_at', 'last_seen_at', 'left_at', 'left_reason'];

    protected function casts(): array
    {
        return [
            'joined_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'left_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Seconds actually present: to the leave time, or to the last sign of life when the browser just stopped. */
    public function seconds(): int
    {
        $end = ($this->left_at === null || $this->left_reason === 'lost') ? $this->last_seen_at : $this->left_at;

        return max(0, (int) $this->joined_at->diffInSeconds($end, true));
    }
}
