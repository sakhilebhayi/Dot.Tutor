<?php

namespace App\Domain\Ops\Http\Controllers;

use App\Domain\Ops\Access\OpsPermission as P;
use App\Domain\Safety\Audit;
use App\Domain\Safety\Enums\IntegrityKind;
use App\Domain\Safety\Enums\ReportPriority;
use App\Domain\Safety\Enums\ScreeningKind;
use App\Domain\Safety\Models\AccountRestriction;
use App\Domain\Safety\Models\GuardianApproval;
use App\Domain\Safety\Models\GuardianLink;
use App\Domain\Safety\Models\Incident;
use App\Domain\Safety\Models\IntegrityCase;
use App\Domain\Safety\Models\Message;
use App\Domain\Safety\Models\ModerationAction;
use App\Domain\Safety\Models\Report;
use App\Domain\Safety\Models\ScreeningCheck;
use App\Domain\Safety\Services\AccountStanding;
use App\Domain\Safety\Services\Incidents;
use App\Domain\Safety\Services\IntegrityCases;
use App\Domain\Safety\Services\Moderation;
use App\Domain\Safety\Services\Screening;
use App\Models\TutorProfile;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Safeguarding operations (brief sections 15 and 20): reports by priority with the child-safety fast path,
 * incidents with escalation timers, academic integrity, minor account controls and communication rules, tutor
 * screening for work with minors. Every action calls the safety domain's own service (which audits it).
 */
class SafeguardingController extends OpsController
{
    public function queue(Request $request): View
    {
        $show = $request->query('show') === 'closed' ? 'closed' : 'open';

        $reports = Report::query()->with(['incident:id,reference,status', 'reporter:id,name', 'reportedUser:id,name'])
            ->when($show === 'open', fn ($q) => $q->whereIn('status', [Report::OPEN, Report::ESCALATED]))
            ->when($show === 'closed', fn ($q) => $q->whereIn('status', [Report::ACTIONED, Report::DISMISSED]))
            ->limit(300)->get()
            ->sortBy($show === 'open'
                ? [fn (Report $a, Report $b) => $a->priority->rank() <=> $b->priority->rank(), fn (Report $a, Report $b) => $a->respond_by <=> $b->respond_by]
                : [fn (Report $a, Report $b) => $b->updated_at <=> $a->updated_at])
            ->values();

        return view('ops.safeguarding.queue', [
            'show' => $show,
            'reports' => $reports,
            'counts' => collect(ReportPriority::cases())->mapWithKeys(fn ($p) => [$p->value => $show === 'open' ? $reports->where('priority', $p)->count() : null])->all(),
            'tabs' => $this->tabs(),
        ]);
    }

    public function report(Request $request, Report $report): View
    {
        Audit::record($request->user(), 'safety.report.viewed', $report);

        $message = $report->reportable instanceof Message ? $report->reportable : null;

        return view('ops.safeguarding.report', [
            'report' => $report->load(['reporter', 'reportedUser', 'incident']),
            'message' => $message,
            'actions' => ModerationAction::query()->where('report_id', $report->id)->orderByDesc('id')->get(),
            'canAct' => $this->can(P::SAFEGUARDING_ACT),
            'tabs' => $this->tabs(),
        ]);
    }

    public function act(Request $request, Report $report, Moderation $moderation): RedirectResponse
    {
        $data = $request->validate([
            'action' => ['required', Rule::in(Moderation::ACTIONS)],
            'reason' => ['required', 'string', 'max:500'],
            'days' => ['nullable', 'integer', 'min:1', 'max:3650'],
            'severity' => ['nullable', Rule::in(Incidents::SEVERITIES)],
        ]);

        $moderation->act($request->user(), $data['action'], $report, null, null, $data['reason'], array_filter(['days' => $data['days'] ?? null, 'severity' => $data['severity'] ?? null]));

        return $this->done(match ($data['action']) {
            'escalate' => 'Escalated: an incident is open with its timer running.',
            'dismiss' => 'Report dismissed with your reason.',
            default => 'Action recorded and the report resolved.',
        });
    }

    public function incidents(): View
    {
        return view('ops.safeguarding.incidents', [
            'incidents' => Incident::query()->with('owner:id,name')
                ->orderByRaw("case when status = 'closed' then 1 else 0 end")->orderByDesc('escalation_level')->orderBy('respond_by')->orderByDesc('id')
                ->paginate(30),
            'canAct' => $this->can(P::SAFEGUARDING_ACT),
            'tabs' => $this->tabs(),
        ]);
    }

    public function incident(Incident $incident): View
    {
        return view('ops.safeguarding.incident', [
            'incident' => $incident->load(['owner:id,name', 'reports']),
            'events' => $incident->events()->get(),
            'actors' => User::query()->whereIn('id', $incident->events()->pluck('actor_id')->filter()->unique())->pluck('name', 'id'),
            'learner' => $incident->learner_id ? User::query()->find($incident->learner_id) : null,
            'canAct' => $this->can(P::SAFEGUARDING_ACT),
            'tabs' => $this->tabs(),
        ]);
    }

    public function openIncident(Request $request, Incidents $incidents): RedirectResponse
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:200'],
            'severity' => ['required', Rule::in(Incidents::SEVERITIES)],
            'learner_email' => ['nullable', 'email'],
        ]);

        $learner = isset($data['learner_email']) ? User::query()->whereRaw('lower(email) = ?', [mb_strtolower($data['learner_email'])])->first() : null;
        if (isset($data['learner_email']) && $learner === null) {
            return back(303)->withErrors(['learner_email' => 'Nobody with that email has an account.'])->withInput();
        }

        $incident = $incidents->open($data['title'], $data['severity'], $request->user(), $learner);

        return redirect()->route('ops.safeguarding.incidents.show', $incident, 303)->with('success', 'Incident '.$incident->reference.' opened. Its timer is running.');
    }

    public function updateIncident(Request $request, Incident $incident, Incidents $incidents): RedirectResponse
    {
        $data = $request->validate([
            'action' => ['required', Rule::in(['acknowledge', 'note', 'refer', 'close'])],
            'body' => [Rule::requiredIf(in_array($request->input('action'), ['note', 'refer', 'close'], true)), 'nullable', 'string', 'max:5000'],
            'external_reference' => ['nullable', 'string', 'max:120'],
        ]);

        $operator = $request->user();
        match ($data['action']) {
            'acknowledge' => $incidents->acknowledge($incident, $operator),
            'note' => $incidents->note($incident, $operator, $data['body']),
            'refer' => $incidents->refer($incident, $operator, $data['body'], $data['external_reference'] ?? null),
            'close' => $incidents->close($incident, $operator, $data['body']),
        };

        return $this->done(match ($data['action']) {
            'acknowledge' => 'You own this incident now. The escalation timer has stopped.',
            'note' => 'Note added to the timeline.',
            'refer' => 'Referral recorded. Nothing was sent from the platform.',
            'close' => 'Incident closed with its outcome; linked reports are closed too.',
        });
    }

    public function integrity(): View
    {
        return view('ops.safeguarding.integrity', [
            'cases' => IntegrityCase::query()->orderByRaw("case when status = 'open' then 0 else 1 end")->orderByDesc('id')->paginate(30),
            'people' => fn (array $ids) => User::query()->whereIn('id', $ids)->pluck('name', 'id'),
            'kinds' => IntegrityKind::cases(),
            'sanctions' => IntegrityCase::SANCTIONS,
            'canAct' => $this->can(P::SAFEGUARDING_ACT),
            'tabs' => $this->tabs(),
        ]);
    }

    public function openCase(Request $request, IntegrityCases $cases): RedirectResponse
    {
        $data = $request->validate([
            'kind' => ['required', Rule::enum(IntegrityKind::class)],
            'summary' => ['required', 'string', 'max:5000'],
            'subject_email' => ['nullable', 'email'],
        ]);

        $subject = isset($data['subject_email']) ? User::query()->whereRaw('lower(email) = ?', [mb_strtolower($data['subject_email'])])->first() : null;
        if (isset($data['subject_email']) && $subject === null) {
            return back(303)->withErrors(['subject_email' => 'Nobody with that email has an account.'])->withInput();
        }

        $cases->open(IntegrityKind::from($data['kind']), $data['summary'], $request->user(), null, $subject);

        return $this->done('Integrity case opened.');
    }

    public function decideCase(Request $request, IntegrityCase $case, IntegrityCases $cases): RedirectResponse
    {
        $data = $request->validate([
            'upheld' => ['required', 'boolean'],
            'finding' => ['required', 'string', 'max:5000'],
            'sanction' => ['required', Rule::in(IntegrityCase::SANCTIONS)],
            'days' => ['nullable', 'integer', 'min:1', 'max:3650'],
        ]);

        $cases->decide($case, $request->user(), (bool) $data['upheld'], $data['finding'], $data['sanction'], $data['days'] ?? null);

        return $this->done('Decision recorded.');
    }

    /** Minor account controls: every learner known to be under 18, their standing, guardians and what waits for a guardian. */
    public function minors(AccountStanding $standing): View
    {
        $adultFrom = now()->subYears((int) config('safety.ages.adult_from', 18))->toDateString();
        $ids = DB::table('safety_guardian_links')->where('status', 'active')->pluck('learner_id')
            ->merge(DB::table('safety_age_records')->where('birth_date', '>', $adultFrom)->pluck('user_id'))
            ->merge(DB::table('learner_profiles')->whereNotNull('birth_month')->where('birth_month', '>', $adultFrom)->pluck('user_id'))
            ->unique()->values();

        $users = User::query()->whereIn('id', $ids)->orderBy('name')->paginate(30);
        $page = $users->getCollection();

        return view('ops.safeguarding.minors', [
            'users' => $users,
            'rows' => $page->map(fn (User $u) => [
                'user' => $u,
                'account' => $standing->summary($u),
                'guardians' => GuardianLink::query()->active()->where('learner_id', $u->id)->count(),
                'waiting' => GuardianApproval::query()->where('learner_id', $u->id)->where('status', GuardianApproval::PENDING)->where('expires_at', '>', now())->count(),
            ])->filter(fn ($r) => $r['account']['is_minor'])->values(),
            'rules' => [
                'approvals' => config('safety.approvals'),
                'unknown_age_policy' => config('safety.unknown_age_policy'),
                'context_required_for' => config('safety.messaging.context_required_for'),
            ],
            'redacted30' => DB::table('safety_messages')->whereNotNull('redactions')->where('created_at', '>=', now()->subDays(30))->count(),
            'suspendedMinors' => AccountRestriction::query()->inEffect()->where('kind', AccountRestriction::SUSPENSION)->whereIn('user_id', $ids)->count(),
            'tabs' => $this->tabs(),
        ]);
    }

    public function screening(Screening $screening): View
    {
        $tutors = TutorProfile::query()->with('user')->where('status', 'approved')->orderBy('id')->get()
            ->map(fn (TutorProfile $p) => ['profile' => $p, 'missing' => $p->user ? $screening->missingKinds($p->user) : []]);

        return view('ops.safeguarding.screening', [
            'pending' => ScreeningCheck::query()->with('user:id,name')->where('status', ScreeningCheck::PENDING_REVIEW)->orderBy('id')->get(),
            'recent' => ScreeningCheck::query()->with('user:id,name')->where('status', '!=', ScreeningCheck::PENDING_REVIEW)->orderByDesc('updated_at')->limit(30)->get(),
            'tutors' => $tutors,
            'kinds' => ScreeningKind::cases(),
            'required' => (array) config('safety.screening.required_kinds'),
            'fourEyes' => (bool) config('safety.screening.four_eyes'),
            'tabs' => $this->tabs(),
        ]);
    }

    public function recordScreening(Request $request, User $user, Screening $screening): RedirectResponse
    {
        $data = $request->validate([
            'kind' => ['required', Rule::enum(ScreeningKind::class)],
            'checked_on' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'expires_on' => ['nullable', 'date_format:Y-m-d', 'after:checked_on'],
            'reference' => ['nullable', 'string', 'max:120'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'evidence' => ['nullable', 'file', 'mimes:pdf,png,jpg,jpeg', 'max:'.config('safety.screening.evidence_max_kb')],
        ]);

        $screening->record($user, ScreeningKind::from($data['kind']), $data, $request->file('evidence'), $request->user());

        return $this->done(config('safety.screening.four_eyes') ? 'Recorded. A second operator must review it before it counts.' : 'Recorded for review.');
    }

    public function reviewScreening(Request $request, ScreeningCheck $check, Screening $screening): RedirectResponse
    {
        $data = $request->validate(['decision' => ['required', Rule::in(['cleared', 'not_cleared'])], 'note' => ['nullable', 'string', 'max:500']]);
        $screening->review($check, $request->user(), $data['decision'] === 'cleared', $data['note'] ?? null);

        return $this->done($data['decision'] === 'cleared' ? 'Cleared.' : 'Marked not cleared.');
    }

    public function revokeScreening(Request $request, ScreeningCheck $check, Screening $screening): RedirectResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:500']]);
        $screening->revoke($check, $request->user(), $data['reason']);

        return $this->done('Screening revoked. Minors cannot book this tutor until a current check is recorded.');
    }

    /** @return list<array{label: string, route: string}> */
    private function tabs(): array
    {
        return [
            ['label' => 'Reports', 'route' => 'ops.safeguarding', 'count' => Report::query()->whereIn('status', [Report::OPEN, Report::ESCALATED])->count()],
            ['label' => 'Incidents', 'route' => 'ops.safeguarding.incidents', 'count' => Incident::query()->where('status', '!=', Incident::CLOSED)->count()],
            ['label' => 'Minors', 'route' => 'ops.safeguarding.minors'],
            ['label' => 'Screening', 'route' => 'ops.safeguarding.screening', 'count' => ScreeningCheck::query()->where('status', ScreeningCheck::PENDING_REVIEW)->count()],
            ['label' => 'Integrity', 'route' => 'ops.safeguarding.integrity', 'count' => IntegrityCase::query()->where('status', IntegrityCase::OPEN)->count()],
            ['label' => 'AI log', 'route' => 'ai.operator.log'],
        ];
    }
}
