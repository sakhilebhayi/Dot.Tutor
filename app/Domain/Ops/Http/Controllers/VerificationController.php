<?php

namespace App\Domain\Ops\Http\Controllers;

use App\Domain\Academic\Enums\LanguageRole;
use App\Domain\Academic\Models\UserLanguage;
use App\Domain\Marketplace\Enums\QualificationStatus;
use App\Domain\Marketplace\Enums\VerificationLevel;
use App\Domain\Marketplace\Exceptions\MarketplaceException;
use App\Domain\Marketplace\Models\TutorQualification;
use App\Domain\Marketplace\Models\VerificationDecision;
use App\Domain\Marketplace\Services\VerificationService;
use App\Domain\Safety\Audit;
use App\Domain\Safety\Models\AuditEntry;
use App\Domain\Safety\Services\Screening;
use App\Models\TutorProfile;
use App\Services\TutorModeration;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Tutor verification (brief section 16): qualification evidence, verification levels on the evidence, tutor
 * applications, teaching languages, and whether a tutor is screened for work with minors (read only here; the
 * screening record is a safeguarding officer's job). Decisions go through the marketplace VerificationService and
 * the pre-R-47 TutorModeration service; both are audited.
 */
class VerificationController extends OpsController
{
    public function index(Request $request): View
    {
        $tab = in_array($request->query('tab'), ['evidence', 'applications', 'languages', 'tutors'], true) ? $request->query('tab') : 'evidence';

        return view('ops.verification.index', [
            'tab' => $tab,
            'counts' => [
                'evidence' => TutorQualification::query()->where('status', QualificationStatus::Submitted->value)->count(),
                'applications' => TutorProfile::query()->where('status', 'pending')->count(),
                'languages' => UserLanguage::query()->where('role', LanguageRole::CanTeachIn->value)->whereNull('verified_at')->count(),
            ],
            'queue' => $tab === 'evidence' ? TutorQualification::query()->with(['evidence', 'tutorProfile.user'])
                ->where('status', QualificationStatus::Submitted->value)->orderBy('submitted_at')->orderBy('id')->paginate(20)->withQueryString() : null,
            'applications' => $tab === 'applications' ? TutorProfile::query()->with(['user', 'subjects'])->where('status', 'pending')->orderBy('created_at')->paginate(20)->withQueryString() : null,
            'languages' => $tab === 'languages' ? UserLanguage::query()->with(['user', 'language'])->where('role', LanguageRole::CanTeachIn->value)
                ->whereIn('user_id', TutorProfile::query()->select('user_id'))->orderByRaw('verified_at is not null')->orderBy('id')->paginate(30)->withQueryString() : null,
            'tutors' => $tab === 'tutors' ? TutorProfile::query()->with('user')->whereIn('status', ['approved', 'suspended'])->orderByDesc('updated_at')->paginate(30)->withQueryString() : null,
        ]);
    }

    public function tutor(TutorProfile $tutorProfile, Screening $screening): View
    {
        $tutorProfile->load(['user', 'subjects']);

        return view('ops.verification.tutor', [
            'tutor' => $tutorProfile,
            'qualifications' => TutorQualification::query()->with('evidence')->where('tutor_profile_id', $tutorProfile->id)->orderByDesc('id')->get(),
            'decisions' => $this->decisions($tutorProfile),
            'languages' => UserLanguage::query()->with('language')->where('user_id', $tutorProfile->user_id)->where('role', LanguageRole::CanTeachIn->value)->get(),
            'levels' => VerificationLevel::cases(),
            'screened' => $tutorProfile->user ? $screening->isClearedForMinors($tutorProfile->user) : false,
            'missingScreening' => $tutorProfile->user ? $screening->missingKinds($tutorProfile->user) : [],
        ]);
    }

    public function decide(Request $request, TutorQualification $qualification, VerificationService $verification): RedirectResponse
    {
        $data = $request->validate([
            'decision' => ['required', Rule::in(['verify', 'reject', 'revoke'])],
            'note' => ['required', 'string', 'min:5', 'max:2000'],
        ]);

        try {
            match ($data['decision']) {
                'verify' => $verification->verifyQualification($qualification, $request->user(), $data['note']),
                'reject' => $verification->rejectQualification($qualification, $request->user(), $data['note']),
                'revoke' => $verification->revokeQualification($qualification, $request->user(), $data['note']),
            };
        } catch (MarketplaceException $e) {
            return $this->refused($e->getMessage());
        }

        return $this->done(match ($data['decision']) {
            'verify' => 'Verified. The tutor\'s storefront now says "Verified by Dot.Tutor" for this record.',
            'reject' => 'Rejected. The record is hidden from the storefront.',
            'revoke' => 'Verification withdrawn. Any level that rested only on it was removed.',
        });
    }

    public function level(Request $request, TutorProfile $tutorProfile, VerificationService $verification): RedirectResponse
    {
        $data = $request->validate([
            'level' => ['nullable', Rule::enum(VerificationLevel::class)],
            'reason' => ['required', 'string', 'min:5', 'max:2000'],
        ]);

        try {
            $verification->assignLevel($tutorProfile, isset($data['level']) ? VerificationLevel::from($data['level']) : null, $request->user(), $data['reason']);
        } catch (MarketplaceException $e) {
            return $this->refused($e->getMessage());
        }

        return $this->done(isset($data['level']) ? 'Verification level set.' : 'Verification level removed.');
    }

    /**
     * Every decision about this tutor, newest first (D-2210): verification decisions (qualifications and levels,
     * append-only in the marketplace) and the profile decisions (approve, reject, suspend, reinstate), which the
     * audit log holds with the operator's reason.
     *
     * @return list<array{at: \Carbon\CarbonInterface|null, what: string, change: string, reason: string}>
     */
    private function decisions(TutorProfile $tutorProfile): array
    {
        $verification = VerificationDecision::query()->where('tutor_profile_id', $tutorProfile->id)->orderByDesc('id')->limit(50)->get()
            ->map(fn (VerificationDecision $d) => [
                'at' => $d->created_at,
                'what' => $d->subject === 'level' ? 'Level' : 'Qualification',
                'change' => str_replace('_', ' ', $d->from_value ?? 'none').' to '.str_replace('_', ' ', $d->to_value ?? 'none'),
                'reason' => (string) $d->reason,
            ]);

        $words = ['approve' => 'approved', 'reject' => 'not approved', 'suspend' => 'suspended', 'reinstate' => 'reinstated'];
        $profile = AuditEntry::query()
            ->where('subject_id', $tutorProfile->id)
            ->whereIn('subject_type', array_unique([$tutorProfile->getMorphClass(), TutorProfile::class]))
            ->where('action', 'like', 'ops.tutor_profile.%')
            ->orderByDesc('id')->limit(50)->get()
            ->map(fn (AuditEntry $e) => [
                'at' => $e->created_at,
                'what' => 'Profile',
                'change' => $words[substr($e->action, strlen('ops.tutor_profile.'))] ?? 'changed',
                'reason' => (string) ($e->context['reason'] ?? ''),
            ]);

        return $verification->concat($profile)->sortByDesc(fn ($d) => $d['at']?->getTimestamp() ?? 0)->values()->all();
    }

    /** Approve, reject, suspend or reinstate a tutor profile (the pre-R-47 application flow), audited here. */
    public function standing(Request $request, TutorProfile $tutorProfile, TutorModeration $moderation): RedirectResponse
    {
        $data = $request->validate([
            'action' => ['required', Rule::in(['approve', 'reject', 'suspend', 'reinstate'])],
            'reason' => [Rule::requiredIf(in_array($request->input('action'), ['reject', 'suspend'], true)), 'nullable', 'string', 'max:2000'],
        ]);

        abort_if($tutorProfile->user_id === $request->user()->id, 403, 'Operators cannot decide their own tutor profile.');

        $done = match ($data['action']) {
            'approve' => $moderation->approve($tutorProfile, $request->user()),
            'reject' => $moderation->reject($tutorProfile, $request->user(), $data['reason']),
            'suspend' => $moderation->suspend($tutorProfile, $request->user(), $data['reason']),
            'reinstate' => $moderation->reinstate($tutorProfile, $request->user()),
        };

        if (! $done) {
            return $this->refused('That profile is no longer in a state this action applies to.');
        }

        Audit::record($request->user(), 'ops.tutor_profile.'.$data['action'], $tutorProfile, array_filter([
            'user_id' => $tutorProfile->user_id,
            'reason' => isset($data['reason']) && $data['reason'] !== '' ? mb_substr($data['reason'], 0, 500) : null,
        ], fn ($v) => $v !== null));

        return $this->done(match ($data['action']) {
            'approve' => 'Tutor approved. They can now be found and booked.',
            'reject' => 'Application rejected with your reason.',
            'suspend' => 'Tutor suspended. Their pending requests were cancelled.',
            'reinstate' => 'Tutor reinstated.',
        });
    }
}
