<?php

namespace App\Domain\Safety\Services;

use App\Domain\Safety\Audit;
use App\Domain\Safety\Contracts\Reportable;
use App\Domain\Safety\Contracts\ReportSink;
use App\Domain\Safety\Enums\IntegrityKind;
use App\Domain\Safety\Enums\ReportCategory;
use App\Domain\Safety\Enums\ReportPriority;
use App\Domain\Safety\Models\Report;
use App\Models\TutorProfile;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Files a report, sets its priority and response time, and takes the child-safety fast path: a report of
 * inappropriate contact with a minor is critical and opens an incident with an escalation timer at once.
 */
class DatabaseReportSink implements ReportSink
{
    public function __construct(
        private readonly AccountStanding $standing,
        private readonly Incidents $incidents,
    ) {}

    public function report(User $reporter, Model $reportable, ReportCategory $category, ?string $details = null): Report
    {
        $existing = Report::query()->where('reporter_id', $reporter->id)
            ->where('reportable_type', $reportable->getMorphClass())->where('reportable_id', $reportable->getKey())
            ->whereIn('status', [Report::OPEN, Report::ESCALATED])->first();

        if ($existing !== null) {
            return $existing;
        }

        $key = 'safety-reports:'.$reporter->id;

        if (RateLimiter::tooManyAttempts($key, (int) config('safety.reports.per_hour'))) {
            throw new ThrottleRequestsException('Too many reports in a short time. Try again later.');
        }

        RateLimiter::hit($key, 3600);

        $reported = $this->reportedUser($reportable);
        $involvedIds = $reportable instanceof Reportable ? $reportable->involvedUserIds() : [];
        $involved = User::query()->whereIn('id', array_filter([...$involvedIds, $reporter->id, $reported?->id]))->get();
        $minor = $involved->first(fn (User $u) => $this->standing->isMinor($u));

        $priority = match (true) {
            $category->isChildSafety() => ReportPriority::Critical,
            $minor !== null && in_array($category, [ReportCategory::Harassment, ReportCategory::Other], true) => ReportPriority::High,
            $category === ReportCategory::Spam => ReportPriority::Low,
            default => ReportPriority::Normal,
        };

        $report = Report::create([
            'reporter_id' => $reporter->id,
            'reportable_type' => $reportable->getMorphClass(),
            'reportable_id' => $reportable->getKey(),
            'reported_user_id' => $reported?->id,
            'category' => $category,
            'priority' => $priority,
            'status' => Report::OPEN,
            'details' => $details !== null ? mb_substr($details, 0, 2000) : null,
            'involves_minor' => $minor !== null,
            'snapshot' => $reportable instanceof Reportable ? $reportable->reportSnapshot() : ['type' => $reportable->getMorphClass(), 'id' => $reportable->getKey()],
            'respond_by' => now()->addHours($priority->responseHours()),
        ]);

        Audit::record($reporter, 'safety.report.filed', $report, [
            'category' => $category->value, 'priority' => $priority->value, 'involves_minor' => $minor !== null,
        ]);

        if ($priority === ReportPriority::Critical) {
            $this->incidents->open('Child-safety report #'.$report->id.': '.$category->label(), 'critical', null, $minor, $report);
        }

        if ($category === ReportCategory::CheatingRequest) {
            app(IntegrityCases::class)->open(IntegrityKind::CheatingRequest, 'Opened from report #'.$report->id.'.', null, $reportable, $reported, $report);
        }

        return $report->fresh();
    }

    private function reportedUser(Model $reportable): ?User
    {
        return match (true) {
            $reportable instanceof Reportable => $reportable->reportedUser(),
            $reportable instanceof User => $reportable,
            $reportable instanceof TutorProfile => $reportable->user,
            isset($reportable->uploader_id) => User::find($reportable->uploader_id),
            isset($reportable->rated_by) => User::find($reportable->rated_by),
            isset($reportable->user_id) => User::find($reportable->user_id),
            default => null,
        };
    }
}
