<?php

namespace Tests\Concerns;

use App\Models\Subject;
use App\Models\Team;
use App\Models\TutorAvailabilityWindow;
use App\Models\TutorProfile;
use App\Models\TutorSession;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Small, explicit builders for marketplace state. Nothing here is seeded
 * demo data: every test builds exactly the rows it asserts on.
 */
trait BuildsMarketplace
{
    protected function makeSubject(string $name = 'Algebra', bool $active = true): Subject
    {
        return Subject::create(['name' => $name, 'level' => 'high_school', 'is_active' => $active]);
    }

    protected function makeUser(string $name = 'Someone'): User
    {
        return User::factory()->withPersonalTeam()->create(['name' => $name]);
    }

    /** A company: a non-personal team linked to an InfoDot company uuid (what Analytics publishing is about). */
    protected function makeCompany(array $attributes = []): Team
    {
        $owner = User::factory()->create();
        $team = Team::factory()->create(array_merge(['user_id' => $owner->id, 'personal_team' => false], $attributes));
        $owner->forceFill(['current_team_id' => $team->id])->save();
        $team->forceFill(['infodot_team_uuid' => (string) Str::uuid()])->save();

        return $team->fresh();
    }

    /** A tutor whose CURRENT team is $team (their sessions count for that company). */
    protected function makeTutorIn(Team $team, array $profile = [], ?Subject $subject = null): TutorProfile
    {
        $user = User::factory()->create(['current_team_id' => $team->id]);
        $subject ??= Subject::query()->first() ?? $this->makeSubject();

        return $this->makeTutor($profile, $subject, $user);
    }

    /** Pin a session's created_at / updated_at without going through the model's timestamps. */
    protected function stampSession(TutorSession $session, ?string $createdAt = null, ?string $updatedAt = null): TutorSession
    {
        \DB::table('tutor_sessions')->where('id', $session->id)->update(array_filter([
            'created_at' => $createdAt, 'updated_at' => $updatedAt,
        ]));

        return $session->fresh();
    }

    protected function makeOperator(): User
    {
        $operator = $this->makeUser('Operator');
        $operator->forceFill(['is_platform_operator' => true])->save();

        return $operator;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    protected function makeTutor(array $attributes = [], ?Subject $subject = null, ?User $user = null): TutorProfile
    {
        $user ??= $this->makeUser('Approved Tutor');
        $subject ??= $this->makeSubject();

        $profile = TutorProfile::create(array_merge([
            'user_id' => $user->id,
            'hourly_rate' => 45,
            'status' => 'approved',
            'timezone' => 'UTC',
        ], $attributes));
        $profile->subjects()->attach($subject->id);

        return $profile;
    }

    /** Open every weekday between the given local times in the tutor's own timezone. */
    protected function openWeek(TutorProfile $profile, string $start = '08:00', string $end = '20:00'): void
    {
        foreach (range(1, 7) as $weekday) {
            TutorAvailabilityWindow::create([
                'tutor_profile_id' => $profile->id,
                'weekday' => $weekday,
                'start_time' => $start,
                'end_time' => $end,
            ]);
        }
    }

    /** A bookable instant: N days ahead at the given local wall-clock time in the tutor's timezone. */
    protected function slotAt(TutorProfile $profile, int $daysAhead, string $time = '10:00'): Carbon
    {
        $tz = $profile->timezone ?: 'UTC';
        [$h, $m] = array_map('intval', explode(':', $time));

        return Carbon::now($tz)->addDays($daysAhead)->setTime($h, $m)->utc();
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    protected function makeSession(TutorProfile $profile, User $student, Subject $subject, array $overrides = []): TutorSession
    {
        return TutorSession::create(array_merge([
            'tutor_profile_id' => $profile->id,
            'student_id' => $student->id,
            'subject_id' => $subject->id,
            'status' => 'pending',
            'starts_at' => now()->addDays(2)->setTime(10, 0),
            'duration_minutes' => 60,
            'rate' => $profile->hourly_rate,
        ], $overrides));
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    protected function bookingPayload(Subject $subject, Carbon $startsAtUtc, array $overrides = []): array
    {
        return array_merge([
            'subject_id' => $subject->id,
            'starts_at' => $startsAtUtc->copy()->utc()->format('Y-m-d\TH:i:s\Z'),
            'duration_minutes' => 60,
            'delivery' => 'online',
        ], $overrides);
    }
}
