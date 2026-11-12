<?php

namespace App\Domain\Ops\Insights;

use App\Domain\Ops\Access\OpsAccess;
use App\Domain\Ops\Access\OpsPermission as P;
use App\Enums\SessionStatus;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * What is waiting for a person, counted from the real queues, limited to the queues this operator's roles can
 * work. Each entry names the queue in the trade's words, how many items wait, how many are past their response
 * time where the domain sets one, and where to work it. Child-safety items always come first.
 */
class Queues
{
    public function __construct(private readonly OpsAccess $access) {}

    /** @return list<array{key: string, title: string, count: int, urgent: int, urgent_label: ?string, route: string, tone: string}> */
    public function for(User $user): array
    {
        $now = now();
        $queues = [];
        $add = function (string $permission, string $key, string $title, int $count, int $urgent, ?string $urgentLabel, string $route, string $tone = 'neutral') use ($user, &$queues) {
            if ($this->access->can($user, $permission)) {
                $queues[] = compact('key', 'title', 'count', 'urgent', 'route', 'tone') + ['urgent_label' => $urgentLabel];
            }
        };

        $openReports = DB::table('safety_reports')->whereIn('status', ['open', 'escalated']);
        $childSafety = (clone $openReports)->where('priority', 'critical')->count();
        $add(P::SAFEGUARDING_VIEW, 'child_safety', 'Child-safety reports', $childSafety, (clone $openReports)->where('priority', 'critical')->where('respond_by', '<', $now)->count(), 'past the one-hour response time', 'ops.safeguarding', $childSafety > 0 ? 'danger' : 'neutral');
        $add(P::SAFEGUARDING_VIEW, 'reports', 'Other safety reports', (clone $openReports)->where('priority', '!=', 'critical')->count(), (clone $openReports)->where('priority', '!=', 'critical')->where('respond_by', '<', $now)->count(), 'overdue', 'ops.safeguarding');
        $add(P::SAFEGUARDING_VIEW, 'incidents', 'Incidents nobody has acknowledged', DB::table('safety_incidents')->where('status', 'open')->count(), DB::table('safety_incidents')->where('status', 'open')->where('respond_by', '<', $now)->count(), 'past their escalation timer', 'ops.safeguarding.incidents', 'warning');
        $add(P::SCREENING_MANAGE, 'screening', 'Screening records waiting for a second reviewer', DB::table('safety_screening_checks')->where('status', 'pending_review')->count(), 0, null, 'ops.safeguarding.screening');
        $add(P::SAFEGUARDING_VIEW, 'integrity', 'Open academic integrity cases', DB::table('safety_integrity_cases')->where('status', 'open')->count(), 0, null, 'ops.safeguarding.integrity');

        $add(P::VERIFICATION_REVIEW, 'qualifications', 'Qualification evidence to review', DB::table('tutor_qualifications')->where('status', 'submitted')->count(), 0, null, 'ops.verification');
        $add(P::VERIFICATION_REVIEW, 'profiles', 'Tutor applications waiting', DB::table('tutor_profiles')->where('status', 'pending')->count(), 0, null, 'ops.verification');

        $add(P::CONTENT_MODERATE, 'held_posts', 'Held room posts', DB::table('community_posts')->where('status', 'held')->count(), 0, null, 'ops.moderation');
        $add(P::CONTENT_MODERATE, 'resources', 'Resources waiting for a source check', DB::table('learning_resources')->where('status', 'pending_review')->count(), 0, null, 'ops.moderation');
        $add(P::CONTENT_MODERATE, 'complaints', 'Copyright and content complaints', DB::table('learning_resource_reports')->whereIn('status', ['open', 'countered'])->count(), 0, null, 'ops.moderation');
        $add(P::CONTENT_MODERATE, 'held_reviews', 'Held tutor reviews', DB::table('session_ratings')->where('status', 'held')->count(), 0, null, 'ops.reviews');

        $add(P::BOOKINGS_VIEW, 'disputes', 'Disputed lessons', DB::table('tutor_sessions')->where('status', SessionStatus::Disputed->value)->count(), 0, null, 'ops.reviews');
        $add(P::SUPPORT_VIEW, 'support', 'Support conversations', DB::table('safety_conversations')->where('context_type', 'support')->whereNull('closed_at')->count(), 0, null, 'ops.support');

        $issues = DB::table('money_finance_issues')->where('status', 'open');
        $add(P::FINANCE_VIEW, 'finance_issues', 'Finance exceptions', (clone $issues)->count(), (clone $issues)->where('severity', 'critical')->count(), 'critical', 'ops.payments', 'warning');
        $add(P::FINANCE_VIEW, 'unmatched', 'Unmatched payments', (clone $issues)->where('kind', 'unmatched_payment')->count(), 0, null, 'ops.payments');
        $add(P::FINANCE_VIEW, 'refunds', 'Refunds not yet processed', DB::table('money_refunds')->whereIn('status', ['requested', 'pending', 'processing', 'failed'])->count(), DB::table('money_refunds')->where('status', 'failed')->count(), 'failed', 'ops.payments');
        $add(P::FINANCE_VIEW, 'card_disputes', 'Card disputes open', DB::table('money_disputes')->where('status', 'open')->count(), 0, null, 'ops.payments');
        $add(P::FINANCE_VIEW, 'payouts', 'Payouts drafted, not yet paid', DB::table('money_payouts')->whereIn('status', ['draft', 'processing'])->count(), 0, null, 'ops.payouts');

        return $queues;
    }
}
