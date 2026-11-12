<?php

namespace App\Domain\Classroom\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One person's place in a classroom: their live state (joined or not, microphone, camera, sharing, quality). */
class ClassroomAttendee extends Model
{
    protected $fillable = [
        'classroom_id', 'user_id', 'role', 'state', 'client_id', 'last_seen_at', 'mic_on', 'cam_on', 'sharing', 'quality', 'join_count',
    ];

    protected function casts(): array
    {
        return [
            'last_seen_at' => 'datetime',
            'mic_on' => 'boolean',
            'cam_on' => 'boolean',
            'sharing' => 'boolean',
            'join_count' => 'integer',
        ];
    }

    /** @return BelongsTo<Classroom, $this> */
    public function classroom(): BelongsTo
    {
        return $this->belongsTo(Classroom::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isPresent(): bool
    {
        return $this->state === 'joined' && $this->last_seen_at !== null
            && $this->last_seen_at->gt(now()->subSeconds((int) config('classroom.presence_seconds')));
    }
}
