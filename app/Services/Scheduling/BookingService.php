<?php

namespace App\Services\Scheduling;

use App\Enums\SessionStatus;
use App\Models\Subject;
use App\Models\TutorProfile;
use App\Models\TutorSession;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The one place a booking is created. Everything that decides "can this
 * student have this slot" runs inside a transaction that holds a row lock on
 * the tutor profile (and the student), so two simultaneous requests for the
 * same hour are checked one after the other, never side by side.
 */
class BookingService
{
    public function __construct(private SlotFinder $slots) {}

    /**
     * @param  array{subject_id: int, starts_at: string, duration_minutes: int, delivery: string, notes?: ?string}  $data
     *
     * @throws ValidationException
     */
    public function book(User $student, TutorProfile $profile, array $data): TutorSession
    {
        $duration = (int) $data['duration_minutes'];
        $start = Carbon::parse($data['starts_at'], $profile->scheduleTimezone())->utc()->startOfMinute();
        $end = $start->copy()->addMinutes($duration);

        return DB::transaction(function () use ($student, $profile, $data, $duration, $start, $end) {
            // Fixed order (student, then tutor) so two bookings can never deadlock each other.
            User::query()->whereKey($student->id)->lockForUpdate()->first();

            return $profile->withLock(function (TutorProfile $locked) use ($student, $data, $duration, $start, $end) {
                abort_unless($locked->isApproved(), 404);

                $subject = $locked->subjects()->where('subjects.id', $data['subject_id'])->first();
                if (! $subject instanceof Subject) {
                    throw ValidationException::withMessages(['subject_id' => 'This tutor does not teach the selected subject.']);
                }
                if (! $subject->is_active) {
                    throw ValidationException::withMessages(['subject_id' => 'That subject is no longer offered for new bookings.']);
                }

                $name = optional($locked->user)->name ?? 'This tutor';
                $reason = $this->slots->refusalReason($locked, $start, $duration);
                if ($reason === 'blocked') {
                    throw ValidationException::withMessages(['starts_at' => "{$name} is not teaching on that date."]);
                }
                if ($reason === 'outside_hours') {
                    throw ValidationException::withMessages(['starts_at' => "That time is outside {$name}'s open hours (times are shown in {$locked->scheduleTimezone()}). Pick one of the listed times."]);
                }

                if ($this->overlapping(TutorSession::query()->where('tutor_profile_id', $locked->id), $start, $end)) {
                    throw ValidationException::withMessages(['starts_at' => 'That time was just taken. Pick another.']);
                }
                if ($this->overlapping(TutorSession::query()->where('student_id', $student->id), $start, $end)) {
                    throw ValidationException::withMessages(['starts_at' => 'You already have a session at that time.']);
                }

                return TutorSession::create([
                    'tutor_profile_id' => $locked->id,
                    'student_id' => $student->id,
                    'subject_id' => $subject->id,
                    'status' => SessionStatus::Pending->value,
                    'delivery' => $data['delivery'],
                    'starts_at' => $start,
                    'duration_minutes' => $duration,
                    // Snapshot: a later rate change never touches a booked session.
                    'rate' => $locked->hourly_rate,
                    'currency' => $locked->currency ?: config('tutor.currency'),
                    'notes' => $data['notes'] ?? null,
                ]);
            });
        });
    }

    /**
     * Does any pending/confirmed session in $query overlap [$start, $end)?
     * Back-to-back (one ends exactly when the next starts) is not an overlap.
     * Portable: candidates are narrowed in SQL by start time, the end is
     * compared in PHP so no driver-specific date arithmetic is needed.
     *
     * @param  Builder<TutorSession>  $query
     */
    private function overlapping($query, Carbon $start, Carbon $end): bool
    {
        $longest = max((array) config('tutor.durations', [120]));

        return $query
            ->whereIn('status', SessionStatus::holdingTime())
            ->where('starts_at', '<', $end->copy()->utc())
            ->where('starts_at', '>', $start->copy()->subMinutes($longest)->utc())
            ->get()
            ->contains(fn (TutorSession $s) => $s->endsAt()->gt($start));
    }
}
