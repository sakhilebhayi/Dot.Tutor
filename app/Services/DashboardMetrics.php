<?php

namespace App\Services;

use App\Enums\SessionStatus;
use App\Enums\TutorProfileStatus;
use App\Models\TutorProfile;
use App\Models\TutorSession;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * The dashboard's headline figures, scoped to who is looking:
 *
 *  - operator  -> platform totals (the only role that may see them)
 *  - tutor     -> only sessions taught on their own profile
 *  - student   -> only sessions they booked
 *
 * Someone who is both a tutor and a student gets both lenses. A brand-new
 * user gets a student lens of zeroes; they are never shown platform numbers.
 * "Value" is rate x time at the rate snapshotted on each session; no money
 * moves through the platform, so it is a record of agreed value, not revenue.
 */
class DashboardMetrics
{
    /**
     * @return array{lenses: list<array{key: string, title: string, figures: list<array{key: string, label: string, value: int|float}>, other_currencies: array<string, float>}>}
     */
    public function forUser(User $user): array
    {
        if ($user->is_platform_operator) {
            return ['lenses' => [$this->platform()]];
        }

        $lenses = [];
        $profile = $user->tutorProfile;

        if ($profile) {
            $lenses[] = $this->lens('tutor', 'Teaching', TutorSession::query()->where('tutor_profile_id', $profile->id));
        }

        $bookedAny = TutorSession::query()->where('student_id', $user->id)->exists();
        if (! $profile || $bookedAny) {
            $lenses[] = $this->lens('student', 'Learning', TutorSession::query()->where('student_id', $user->id));
        }

        return ['lenses' => $lenses];
    }

    /** @return array<string, mixed> */
    private function platform(): array
    {
        $lens = $this->lens('platform', 'Platform', TutorSession::query());

        $lens['figures'][] = [
            'key' => 'tutors_approved', 'label' => 'Approved tutors',
            'value' => TutorProfile::query()->where('status', TutorProfileStatus::Approved->value)->count(),
        ];
        $lens['figures'][] = [
            'key' => 'applications_pending', 'label' => 'Applications waiting',
            'value' => TutorProfile::query()->where('status', TutorProfileStatus::Pending->value)->count(),
        ];

        return $lens;
    }

    /**
     * @param  Builder<TutorSession>  $sessions
     * @return array<string, mixed>
     */
    private function lens(string $key, string $title, Builder $sessions): array
    {
        $holding = SessionStatus::holdingTime();
        $in = implode(',', array_fill(0, count($holding), '?'));

        // One pass for the counts, one grouped pass for value per currency.
        $row = (clone $sessions)->toBase()->selectRaw(
            "count(*) as total,
             coalesce(sum(case when status in ({$in}) and starts_at >= ? then 1 else 0 end), 0) as upcoming,
             coalesce(sum(case when status = ? then 1 else 0 end), 0) as completed",
            [...$holding, now()->utc(), SessionStatus::Completed->value],
        )->first();

        $value = (clone $sessions)
            ->where('status', SessionStatus::Completed->value)
            ->selectRaw('currency, coalesce(sum(rate * duration_minutes / 60.0), 0) as amount')
            ->groupBy('currency')
            ->pluck('amount', 'currency')
            ->map(fn ($v) => (float) $v);

        $primary = config('tutor.currency');

        return [
            'key' => $key,
            'title' => $title,
            'figures' => [
                ['key' => 'sessions_total', 'label' => 'Sessions', 'value' => (int) $row->total],
                ['key' => 'sessions_upcoming', 'label' => 'Upcoming', 'value' => (int) $row->upcoming],
                ['key' => 'sessions_completed', 'label' => 'Completed', 'value' => (int) $row->completed],
                ['key' => 'value_completed', 'label' => 'Completed lesson value', 'value' => (float) ($value[$primary] ?? 0)],
            ],
            // Sessions booked under an earlier currency setting are listed apart, never added in.
            'other_currencies' => $value->except($primary)->all(),
        ];
    }
}
