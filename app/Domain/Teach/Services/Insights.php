<?php

namespace App\Domain\Teach\Services;

use App\Domain\Learning\Models\Course;
use App\Domain\Learning\Models\Enrolment;
use App\Domain\Marketplace\Models\TutorSessionEvent;
use App\Domain\Marketplace\Services\TutorPresenter;
use App\Domain\Money\Models\Earning;
use App\Domain\Money\Models\OrderLine;
use App\Domain\Teach\Models\ReferralLink;
use App\Domain\Teach\Models\ReferralVisit;
use App\Models\TutorProfile;
use App\Models\TutorSession;
use Carbon\CarbonImmutable;

/**
 * Business insights from the tutor's own records only (brief sections 7 and 18). Every figure is counted from rows
 * the platform holds; what the platform does not track (storefront views) is said, never estimated.
 */
class Insights
{
    public function __construct(private TutorPresenter $presenter) {}

    /** @return array<string, mixed> */
    public function for(TutorProfile $profile, string $tz): array
    {
        $now = CarbonImmutable::now($tz);
        $since90 = $now->subDays(90);
        $sessionIds = TutorSession::query()->where('tutor_profile_id', $profile->id)->select('id');

        // Lesson requests received per week, last 12 weeks (week starting Monday, tutor's time zone).
        $weekStart = $now->startOfWeek(CarbonImmutable::MONDAY)->subWeeks(11)->startOfDay();
        $requests = TutorSession::query()->where('tutor_profile_id', $profile->id)->where('kind', '!=', 'group')
            ->where('created_at', '>=', $weekStart->utc())->get(['created_at']);
        // Each request is bucketed once by its timestamp against the local week starts (DST-safe), instead of
        // twelve timezone conversions per request (R-47 D-1605).
        $bounds = array_map(fn (int $i) => $weekStart->addWeeks($i)->getTimestamp(), range(0, 12));
        $perWeek = array_fill(0, 12, 0);
        foreach ($requests as $request) {
            $ts = $request->created_at->getTimestamp();
            for ($i = 0; $i < 12; $i++) {
                if ($ts >= $bounds[$i] && $ts < $bounds[$i + 1]) {
                    $perWeek[$i]++;
                    break;
                }
            }
        }
        $weeks = [];
        for ($i = 0; $i < 12; $i++) {
            $from = $weekStart->addWeeks($i);
            $weeks[] = [
                'label' => $from->format('j M'),
                'from' => $from,
                'value' => $perWeek[$i],
            ];
        }

        // How requests were answered in the last 90 days, from the booking timeline.
        $events = TutorSessionEvent::query()->whereIn('tutor_session_id', $sessionIds)->where('created_at', '>=', $since90->utc())
            ->whereIn('event', ['requested', 'accepted', 'declined'])->selectRaw('event, count(*) as n')->groupBy('event')->pluck('n', 'event');

        // Net earnings per month, last 6 months (sales less reversals, by the month the earning was recorded).
        $monthStart = $now->startOfMonth()->subMonths(5);
        $earnings = Earning::query()->where('seller_id', $profile->user_id)->where('status', '!=', Earning::CANCELLED)
            ->where('created_at', '>=', $monthStart->utc())->get(['net_minor', 'created_at', 'currency']);
        $zone = new \DateTimeZone($tz);
        $byMonth = [];
        foreach ($earnings as $earning) {
            $key = (new \DateTimeImmutable('@'.$earning->created_at->getTimestamp()))->setTimezone($zone)->format('Y-m');
            $byMonth[$key] = ($byMonth[$key] ?? 0) + (int) $earning->net_minor;
        }
        $months = [];
        for ($i = 0; $i < 6; $i++) {
            $from = $monthStart->addMonths($i);
            $months[] = [
                'label' => $from->format('M'),
                'title' => $from->format('F Y'),
                'value' => (int) ($byMonth[$from->format('Y-m')] ?? 0),
            ];
        }

        // Students: who came back for a second completed lesson.
        $completed = TutorSession::query()->where('tutor_profile_id', $profile->id)->where('status', 'completed')->whereNotNull('student_id')
            ->selectRaw('student_id, count(*) as n')->groupBy('student_id')->pluck('n', 'student_id');

        // Courses: enrolments and paid sales.
        $courseIds = $profile->user_id ? Course::query()->where('owner_id', $profile->user_id)->pluck('id') : collect();
        $courseSales = OrderLine::query()->where('seller_id', $profile->user_id)->where('purchasable_type', 'course')
            ->whereHas('order', fn ($q) => $q->whereIn('status', ['paid', 'partially_refunded']));

        // Referral link: signed-in visits and the lesson requests those visitors made within 30 days after.
        $link = ReferralLink::query()->where('tutor_profile_id', $profile->id)->first();
        $visits = $link ? ReferralVisit::query()->where('teach_referral_link_id', $link->id)->where('visited_at', '>=', $since90->utc())->get() : collect();
        $attributed = 0;
        foreach ($visits->groupBy('visitor_id') as $visitorId => $rows) {
            $first = $rows->min('visited_at');
            $attributed += TutorSession::query()->where('tutor_profile_id', $profile->id)->where('student_id', $visitorId)
                ->whereBetween('created_at', [$first, $first->copy()->addDays(30)])->exists() ? 1 : 0;
        }

        return [
            'weeks' => $weeks,
            'requests_12w' => array_sum(array_column($weeks, 'value')),
            'answered' => [
                'requested' => (int) ($events['requested'] ?? 0),
                'accepted' => (int) ($events['accepted'] ?? 0),
                'declined' => (int) ($events['declined'] ?? 0),
            ],
            'months' => $months,
            'currency' => $earnings->first()?->currency ?? config('tutor.currency', 'ZAR'),
            'students' => [
                'with_completed' => $completed->count(),
                'returning' => $completed->filter(fn ($n) => $n >= 2)->count(),
            ],
            'courses' => [
                'count' => $courseIds->count(),
                'enrolments' => Enrolment::query()->whereIn('course_id', $courseIds)->count(),
                'paid_sales' => (clone $courseSales)->count(),
                'net_minor' => (int) (clone $courseSales)->sum('seller_net_minor'),
            ],
            'reviews' => $this->presenter->reviewSummary($profile),
            'referral' => [
                'visitors' => $visits->pluck('visitor_id')->unique()->count(),
                'visits' => $visits->count(),
                'attributed' => $attributed,
            ],
        ];
    }
}
