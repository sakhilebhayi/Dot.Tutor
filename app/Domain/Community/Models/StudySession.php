<?php

namespace App\Domain\Community\Models;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A scheduled study session of a group, or a workshop of a tutor room. Times are stored in UTC with the organiser's timezone. */
class StudySession extends Model
{
    protected $table = 'community_sessions';

    protected $fillable = ['room_id', 'group_id', 'created_by_id', 'title', 'starts_at', 'duration_minutes', 'timezone', 'where_note', 'uid', 'cancelled_at'];

    protected function casts(): array
    {
        return ['starts_at' => 'immutable_datetime', 'cancelled_at' => 'datetime', 'duration_minutes' => 'integer'];
    }

    /** @return BelongsTo<StudyGroup, $this> */
    public function group(): BelongsTo
    {
        return $this->belongsTo(StudyGroup::class, 'group_id');
    }

    /** @return BelongsTo<Room, $this> */
    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class);
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }

    /** @return HasMany<SessionAttendance, $this> */
    public function attendance(): HasMany
    {
        return $this->hasMany(SessionAttendance::class, 'session_id');
    }

    public function endsAt(): CarbonImmutable
    {
        return $this->starts_at->addMinutes($this->duration_minutes);
    }

    public function localStart(): CarbonImmutable
    {
        return $this->starts_at->setTimezone($this->timezone);
    }

    public function hasStarted(): bool
    {
        return $this->starts_at->isPast();
    }
}
