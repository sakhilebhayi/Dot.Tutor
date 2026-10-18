<?php

namespace App\Services\Analytics;

use App\Enums\SessionStatus;
use App\Enums\TutorProfileStatus;
use App\Models\Team;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The daily numbers this app publishes to Dot.Analytics, for one company at a time. Pure queries: nothing
 * is written, and only counts come out (no names, emails, notes or rows).
 *
 * The keys are exactly Dot.Analytics' catalogue for platform `tutor` (config/metrics.php there):
 * sessions_7d, completed_7d, active_tutors. None is money, so there is no `currency`.
 *
 * Which company? Dot.Tutor is a user-scoped marketplace: sessions and tutor profiles carry no team of their
 * own. A company here is a non-personal team linked to InfoDot, and a session or tutor counts for the
 * company that is its TUTOR's current team (users.current_team_id) at the time of the run. The student's team
 * is irrelevant. A tutor whose current team is personal or unlinked belongs to no company and is not counted
 * anywhere; there is no platform-wide fallback.
 *
 * There is no signed-in-user team scope in this app and none is relied on: the team is joined explicitly, so
 * the numbers are the same whoever (or nobody) is authenticated.
 *
 * Windows are UTC and rolling: the 7 days ending at $asOf, both ends inclusive.
 *  - sessions_7d: sessions booked (created) in the window, whatever became of them since.
 *  - completed_7d: sessions that reached `completed` in the window. There is no completed_at column; a
 *    completed session is never edited again (only the complete action writes that status), so its
 *    updated_at is the moment it was marked complete.
 *  - active_tutors: approved tutor profiles right now.
 */
class MetricsCollector
{
    /** @return Collection<int, Team> companies Analytics can be told about, optionally just one uuid */
    public function companies(?string $uuid = null): Collection
    {
        return Team::query()
            ->where('personal_team', false)
            ->whereNotNull('infodot_team_uuid')
            ->where('infodot_team_uuid', '!=', '')
            ->when($uuid !== null, fn ($q) => $q->where('infodot_team_uuid', $uuid))
            ->orderBy('id')
            ->get();
    }

    /**
     * @return array{metrics: array<string, int>, omitted: array<string, string>}
     */
    public function collect(Team $team, ?CarbonInterface $asOf = null): array
    {
        $end = ($asOf ?? now())->copy()->utc();
        $start = $end->copy()->subDays(7);

        $sessions = DB::table('tutor_sessions')
            ->join('tutor_profiles', 'tutor_profiles.id', '=', 'tutor_sessions.tutor_profile_id')
            ->join('users', 'users.id', '=', 'tutor_profiles.user_id')
            ->where('users.current_team_id', $team->id)
            ->selectRaw('COALESCE(SUM(CASE WHEN tutor_sessions.created_at >= ? AND tutor_sessions.created_at <= ? THEN 1 ELSE 0 END), 0) as booked', [$start, $end])
            ->selectRaw('COALESCE(SUM(CASE WHEN tutor_sessions.status = ? AND tutor_sessions.updated_at >= ? AND tutor_sessions.updated_at <= ? THEN 1 ELSE 0 END), 0) as completed', [SessionStatus::Completed->value, $start, $end])
            ->first();

        $tutors = DB::table('tutor_profiles')
            ->join('users', 'users.id', '=', 'tutor_profiles.user_id')
            ->where('users.current_team_id', $team->id)
            ->where('tutor_profiles.status', TutorProfileStatus::Approved->value)
            ->count();

        return [
            'metrics' => [
                'sessions_7d' => (int) $sessions->booked,
                'completed_7d' => (int) $sessions->completed,
                'active_tutors' => $tutors,
            ],
            'omitted' => [],
        ];
    }
}
