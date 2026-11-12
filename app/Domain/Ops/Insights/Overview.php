<?php

namespace App\Domain\Ops\Insights;

use App\Domain\Money\Models\Order;
use App\Domain\Money\Money;
use App\Enums\SessionStatus;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The command centre's overview, computed from the real tables on every request (brief section 20: "all dashboard
 * metrics must come from real data"). Nothing is cached, estimated or padded: a figure the platform does not record
 * is returned as null with the reason, and the screen prints a dash and that reason. Periods are calendar weeks and
 * months in the platform timezone (config tutor.timezone).
 */
class Overview
{
    public const WINDOW_DAYS = 30;

    private CarbonImmutable $now;

    private string $tz;

    public function __construct()
    {
        $this->tz = (string) config('tutor.timezone', 'Africa/Johannesburg');
        $this->now = CarbonImmutable::now($this->tz);
    }

    /** @return array<string, mixed> */
    public function compute(bool $withMoney): array
    {
        $since = $this->now->subDays(self::WINDOW_DAYS)->utc();

        return [
            'window_days' => self::WINDOW_DAYS,
            'people' => $this->people($since),
            'lessons' => $this->lessons($since),
            'weekly' => $this->weeklyBookings(12),
            'learning' => $this->learning($since),
            'languages' => $this->languages(),
            'geography' => $this->geography(),
            'satisfaction' => $this->satisfaction(),
            'cohorts' => $this->cohorts(6),
            'money' => $withMoney ? $this->money($since) : null,
        ];
    }

    /** @return array<string, int> */
    private function people(CarbonImmutable $since): array
    {
        $bookers = DB::table('tutor_sessions')->whereNotNull('student_id')->where('starts_at', '>=', $since)
            ->whereNotIn('status', [SessionStatus::Cancelled->value])->distinct()->pluck('student_id');
        $enrolled = DB::table('learning_enrolments')->where('updated_at', '>=', $since)->distinct()->pluck('user_id');

        return [
            'learners_active' => $bookers->merge($enrolled)->unique()->count(),
            'tutors_approved' => DB::table('tutor_profiles')->where('status', 'approved')->count(),
            'tutors_active' => DB::table('tutor_sessions')->join('tutor_profiles', 'tutor_profiles.id', '=', 'tutor_sessions.tutor_profile_id')
                ->where('tutor_sessions.starts_at', '>=', $since)
                ->whereIn('tutor_sessions.status', [SessionStatus::Confirmed->value, SessionStatus::InProgress->value, SessionStatus::Completed->value])
                ->distinct()->count('tutor_profiles.id'),
            'tutors_pending' => DB::table('tutor_profiles')->where('status', 'pending')->count(),
            'guardians_linked' => DB::table('safety_guardian_links')->where('status', 'active')->distinct()->count('guardian_id'),
        ];
    }

    /** @return array<string, int|float|null> */
    private function lessons(CarbonImmutable $since): array
    {
        $rows = DB::table('tutor_sessions')->where('starts_at', '>=', $since)->where('starts_at', '<=', $this->now->utc())
            ->select('status', DB::raw('count(*) as n'))->groupBy('status')->pluck('n', 'status');

        $completed = (int) ($rows[SessionStatus::Completed->value] ?? 0);
        $noShow = (int) ($rows[SessionStatus::NoShow->value] ?? 0);

        return [
            'requested' => (int) DB::table('tutor_sessions')->where('created_at', '>=', $since)->count(),
            'completed' => $completed,
            'no_show' => $noShow,
            'cancelled' => (int) ($rows[SessionStatus::Cancelled->value] ?? 0),
            'disputed' => (int) DB::table('tutor_sessions')->where('status', SessionStatus::Disputed->value)->count(),
            // Completion: of the lessons whose start time has passed and that were held or missed, how many were held.
            'completion_rate' => ($completed + $noShow) > 0 ? round($completed / ($completed + $noShow) * 100, 1) : null,
        ];
    }

    /**
     * Lessons requested per calendar week (Monday start, platform timezone), oldest first, the current week last.
     *
     * @return list<array{label: string, start: string, value: int, current: bool}>
     */
    private function weeklyBookings(int $weeks): array
    {
        $start = $this->now->startOfWeek()->subWeeks($weeks - 1);
        $counts = array_fill(0, $weeks, 0);
        // Week boundaries as timestamps (local Mondays, so DST-correct) and plain integer comparisons per row:
        // a Carbon object per lesson was most of the overview's time at volume (R-47 D-1605).
        $bounds = array_map(fn (int $i) => $start->addWeeks($i)->getTimestamp(), range(0, $weeks));

        DB::table('tutor_sessions')->where('created_at', '>=', $start->utc())->select('id', 'created_at')
            ->lazyById(2000)->each(function ($row) use ($bounds, &$counts, $weeks) {
                $index = $this->bucket($bounds, self::timestamp($row->created_at));
                if ($index !== null && $index < $weeks) {
                    $counts[$index]++;
                }
            });

        return collect(range(0, $weeks - 1))->map(fn (int $i) => [
            'label' => $start->addWeeks($i)->format('j M'),
            'start' => $start->addWeeks($i)->toDateString(),
            'value' => $counts[$i],
            'current' => $i === $weeks - 1,
        ])->all();
    }

    /** @return array<string, int|null> */
    private function learning(CarbonImmutable $since): array
    {
        return [
            'courses_published' => DB::table('learning_courses')->where('status', 'published')->count(),
            'enrolments' => DB::table('learning_enrolments')->where('created_at', '>=', $since)->count(),
            'resources_published' => DB::table('learning_resources')->where('status', 'published')->count(),
            'resources_added' => DB::table('learning_resources')->where('created_at', '>=', $since)->count(),
            'recommendations' => DB::table('learning_resource_recommendations')->where('created_at', '>=', $since)->count(),
            'attempts' => DB::table('learning_attempts')->whereNotNull('submitted_at')->where('submitted_at', '>=', $since)->count(),
            'room_questions' => DB::table('community_posts')->where('kind', 'question')->where('created_at', '>=', $since)->count(),
            'room_answers' => DB::table('community_posts')->where('kind', 'answer')->where('created_at', '>=', $since)->count(),
        ];
    }

    /** @return array{teaching: Collection, preferred: Collection} */
    private function languages(): array
    {
        $count = fn (string $role, ?callable $scope = null) => DB::table('user_languages')
            ->join('languages', 'languages.id', '=', 'user_languages.language_id')
            ->where('user_languages.role', $role)
            ->when($scope, $scope)
            ->select('languages.code', 'languages.name_en as name', 'languages.name_native as native_name', DB::raw('count(distinct user_languages.user_id) as n'))
            ->groupBy('languages.code', 'languages.name_en', 'languages.name_native')
            ->orderByDesc('n')->orderBy('languages.name_en')->get();

        return [
            'teaching' => $count('can_teach_in', fn ($q) => $q->whereIn('user_languages.user_id', DB::table('tutor_profiles')->where('status', 'approved')->select('user_id'))),
            'preferred' => $count('prefers_teaching'),
        ];
    }

    /** @return array{countries: Collection, curricula: Collection, profiles: int} */
    private function geography(): array
    {
        return [
            'profiles' => DB::table('learner_profiles')->count(),
            'countries' => DB::table('learner_profiles')->join('countries', 'countries.id', '=', 'learner_profiles.country_id')
                ->select('countries.name', DB::raw('count(*) as n'))->groupBy('countries.name')->orderByDesc('n')->get(),
            'curricula' => DB::table('learner_profiles')->join('curricula', 'curricula.id', '=', 'learner_profiles.curriculum_id')
                ->select(DB::raw('coalesce(curricula.short_name, curricula.name) as name'), DB::raw('count(*) as n'))
                ->groupBy('curricula.short_name', 'curricula.name')->orderByDesc('n')->get(),
        ];
    }

    /** @return array<string, mixed> */
    private function satisfaction(): array
    {
        $lessons = DB::table('session_ratings')->where('status', 'published')->select('rating', DB::raw('count(*) as n'))->groupBy('rating')->pluck('n', 'rating');
        $courses = DB::table('learning_course_reviews')->where('status', 'published')->select('rating', DB::raw('count(*) as n'))->groupBy('rating')->pluck('n', 'rating');

        $summarise = function (Collection $counts): array {
            $total = (int) $counts->sum();
            $distribution = collect([5, 4, 3, 2, 1])->map(fn (int $stars) => ['stars' => $stars, 'n' => (int) ($counts[$stars] ?? 0)])->all();
            $sum = collect($distribution)->sum(fn ($d) => $d['stars'] * $d['n']);

            return ['total' => $total, 'average' => $total > 0 ? round($sum / $total, 2) : null, 'distribution' => $distribution];
        };

        return ['lessons' => $summarise($lessons), 'courses' => $summarise($courses)];
    }

    /**
     * Learner retention: learners grouped by the month of their first lesson (held or booked and not cancelled),
     * and the share of each group with another lesson in each later month. Months that have not happened are null.
     *
     * @return list<array{month: string, size: int, cells: list<float|null>}>
     */
    private function cohorts(int $months): array
    {
        $first = $this->now->startOfMonth()->subMonths($months - 1);
        $active = []; // learner => [monthIndex => true]
        $firstSeen = []; // learner => monthIndex of their first lesson ever
        $holding = [SessionStatus::Confirmed->value, SessionStatus::InProgress->value, SessionStatus::Completed->value, SessionStatus::NoShow->value];
        $bounds = array_map(fn (int $i) => $first->addMonths($i)->getTimestamp(), range(0, $months));

        // First lesson ever per learner in one grouped query; learners first seen before the window are in no cohort.
        DB::table('tutor_sessions')->whereNotNull('student_id')->whereIn('status', $holding)
            ->groupBy('student_id')->select('student_id', DB::raw('min(starts_at) as first_at'))->get()
            ->each(function ($row) use ($bounds, $first, &$firstSeen) {
                $ts = self::timestamp($row->first_at);
                $firstSeen[$row->student_id] = $ts < $first->getTimestamp() ? -1 : ($this->bucket($bounds, $ts) ?? PHP_INT_MAX);
            });
        // Activity only inside the window (the cells look at months 1 to 3 after each cohort month).
        DB::table('tutor_sessions')->whereNotNull('student_id')->whereIn('status', $holding)->where('starts_at', '>=', $first->utc())
            ->select('id', 'student_id', 'starts_at')
            ->lazyById(2000)->each(function ($row) use ($bounds, &$active) {
                $index = $this->bucket($bounds, self::timestamp($row->starts_at));
                if ($index !== null) {
                    $active[$row->student_id][$index] = true;
                }
            });

        $current = $months - 1;
        $rows = [];
        for ($m = 0; $m < $months; $m++) {
            $members = array_keys(array_filter($firstSeen, fn (int $i) => $i === $m));
            $size = count($members);
            $cells = [];
            for ($k = 1; $k <= 3; $k++) {
                if ($m + $k > $current) {
                    $cells[] = null;

                    continue;
                }
                $cells[] = $size === 0 ? null : round(count(array_filter($members, fn ($id) => isset($active[$id][$m + $k]))) / $size * 100);
            }
            $rows[] = ['month' => $first->addMonths($m)->format('M Y'), 'size' => $size, 'cells' => $cells];
        }

        return $rows;
    }

    /** @return array<string, int|string|null> */
    private function money(CarbonImmutable $since): array
    {
        $paidLines = DB::table('money_order_lines')->join('money_orders', 'money_orders.id', '=', 'money_order_lines.order_id')
            ->whereNotNull('money_orders.paid_at')->where('money_orders.paid_at', '>=', $since);

        $ledger = fn (string $account) => (int) DB::table('money_ledger_entries')->where('account', $account)->where('created_at', '>=', $since)
            ->sum(DB::raw('credit_minor - debit_minor'));

        return [
            'currency' => Money::defaultCurrency(),
            'paid_orders' => (int) DB::table('money_orders')->whereNotNull('paid_at')->where('paid_at', '>=', $since)->count(),
            'cash_minor' => (int) (clone $paidLines)->sum('money_order_lines.cash_minor'),
            'course_sales' => (int) (clone $paidLines)->whereIn('money_order_lines.purchasable_type', ['course', 'course_bundle'])->count(),
            'course_sales_minor' => (int) (clone $paidLines)->whereIn('money_order_lines.purchasable_type', ['course', 'course_bundle'])->sum('money_order_lines.cash_minor'),
            'resource_sales' => (int) (clone $paidLines)->where('money_order_lines.purchasable_type', 'resource')->count(),
            'commission_minor' => $ledger('platform_commission'),
            'refunds_minor' => (int) DB::table('money_refunds')->where('status', 'processed')->where('processed_at', '>=', $since)->sum('amount_minor'),
            'tutor_earnings_minor' => (int) DB::table('money_earnings')->where('created_at', '>=', $since)->sum('net_minor'),
            'open_orders' => (int) Order::query()->whereIn('status', ['pending_payment', 'awaiting_guardian'])->count(),
        ];
    }

    /** A stored UTC datetime as a timestamp (both engines' formats). */
    private static function timestamp(string $value): int
    {
        return (int) strtotime($value.(preg_match('/[zZ]|[+-]\d\d:?\d\d$/', $value) ? '' : ' UTC'));
    }

    /**
     * The index i with bounds[i] <= ts < bounds[i + 1], or null outside them.
     *
     * @param  list<int>  $bounds
     */
    private function bucket(array $bounds, int $ts): ?int
    {
        if ($ts < $bounds[0] || $ts >= $bounds[count($bounds) - 1]) {
            return null;
        }
        $lo = 0;
        $hi = count($bounds) - 1;
        while ($hi - $lo > 1) {
            $mid = intdiv($lo + $hi, 2);
            if ($ts >= $bounds[$mid]) {
                $lo = $mid;
            } else {
                $hi = $mid;
            }
        }

        return $lo;
    }
}
