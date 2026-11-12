<?php

namespace App\Domain\Classroom\Models;

use App\Domain\Marketplace\Enums\SeatStatus;
use App\Domain\Safety\Contracts\InvolvesPeople;
use App\Models\TutorProfile;
use App\Models\TutorSession;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * The live classroom of one booked lesson (a tutor_sessions row, one-to-one or group). The lesson itself, its state
 * machine and its money belong to the marketplace; this row holds only what happens in the room.
 */
class Classroom extends Model implements InvolvesPeople
{
    protected $fillable = [
        'tutor_session_id', 'room_key', 'status', 'started_at', 'ended_at', 'ended_reason', 'external_url',
        'tutor_notes', 'summary', 'summary_source', 'summary_published_at', 'shared_notes', 'shared_notes_version',
        'shared_notes_updated_by', 'board_pages', 'reminder_emitted_at',
    ];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'ended_at' => 'datetime',
            'summary_published_at' => 'datetime',
            'reminder_emitted_at' => 'datetime',
            'shared_notes_version' => 'integer',
            'board_pages' => 'integer',
        ];
    }

    /** @return BelongsTo<TutorSession, $this> */
    public function session(): BelongsTo
    {
        return $this->belongsTo(TutorSession::class, 'tutor_session_id');
    }

    /** @return HasMany<ClassroomAttendee, $this> */
    public function attendees(): HasMany
    {
        return $this->hasMany(ClassroomAttendee::class);
    }

    /** @return HasMany<AttendanceSpan, $this> */
    public function attendance(): HasMany
    {
        return $this->hasMany(AttendanceSpan::class);
    }

    /** @return HasMany<ClassroomMessage, $this> */
    public function messages(): HasMany
    {
        return $this->hasMany(ClassroomMessage::class);
    }

    /** @return HasMany<BoardOp, $this> */
    public function boardOps(): HasMany
    {
        return $this->hasMany(BoardOp::class);
    }

    public function isGroup(): bool
    {
        return $this->session->isGroup();
    }

    public function tutorUserId(): ?int
    {
        $id = TutorProfile::query()->whereKey($this->session->tutor_profile_id)->value('user_id');

        return $id === null ? null : (int) $id;
    }

    /** @return list<int> the learners booked into the lesson (a one-to-one learner, or every confirmed group seat) */
    public function learnerIds(): array
    {
        $session = $this->session;

        if (! $session->isGroup()) {
            return $session->student_id ? [(int) $session->student_id] : [];
        }

        return $session->participants()
            ->whereIn('status', [SeatStatus::Confirmed->value, SeatStatus::Attended->value])
            ->orderBy('learner_id')->pluck('learner_id')->map(fn ($id) => (int) $id)->all();
    }

    /** InvolvesPeople: the safeguarding gate asks about everyone booked into a group lesson. */
    public function safetyParticipantIds(): array
    {
        return array_values(array_unique(array_filter([$this->tutorUserId(), ...$this->learnerIds()])));
    }

    public function isEnded(): bool
    {
        return $this->status === 'ended';
    }
}
