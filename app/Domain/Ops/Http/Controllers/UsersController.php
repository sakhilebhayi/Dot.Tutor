<?php

namespace App\Domain\Ops\Http\Controllers;

use App\Domain\Ops\Access\OpsPermission as P;
use App\Domain\Safety\Audit;
use App\Domain\Safety\Models\AccountRestriction;
use App\Domain\Safety\Models\AgeRecord;
use App\Domain\Safety\Models\AuditEntry;
use App\Domain\Safety\Models\GuardianLink;
use App\Domain\Safety\Models\ModerationAction;
use App\Domain\Safety\Models\Report;
use App\Domain\Safety\Services\AccountStanding;
use App\Domain\Safety\Services\Guardians;
use App\Domain\Safety\Services\Messenger;
use App\Domain\Safety\Services\Moderation;
use App\Models\TutorProfile;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Platform users (brief section 20): learners, tutors, guardians, operators, minors flagged, restrictions and
 * suspensions with their history. Actions go through safety's Moderation service and the audit log.
 */
class UsersController extends OpsController
{
    public const ROLES = ['learner' => 'Learners', 'tutor' => 'Tutors', 'guardian' => 'Guardians', 'operator' => 'Operators'];

    public const FLAGS = ['minor' => 'Minors', 'suspended' => 'Suspended', 'warned' => 'Warned'];

    public function index(Request $request, AccountStanding $standing): View
    {
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:120'],
            'role' => ['nullable', Rule::in(array_keys(self::ROLES))],
            'flag' => ['nullable', Rule::in(array_keys(self::FLAGS))],
        ]);

        $users = User::query()
            ->when($filters['q'] ?? null, function (Builder $q, string $term) {
                $like = '%'.mb_strtolower($term).'%';
                $q->where(fn ($w) => $w->whereRaw('lower(name) like ?', [$like])->orWhereRaw('lower(email) like ?', [$like]));
            })
            ->when($filters['role'] ?? null, fn (Builder $q, string $role) => match ($role) {
                'learner' => $q->where(fn ($w) => $w->whereIn('id', DB::table('learner_profiles')->select('user_id'))->orWhereIn('id', DB::table('tutor_sessions')->whereNotNull('student_id')->select('student_id'))),
                'tutor' => $q->whereIn('id', DB::table('tutor_profiles')->select('user_id')),
                'guardian' => $q->whereIn('id', DB::table('safety_guardian_links')->where('status', 'active')->select('guardian_id')),
                'operator' => $q->where('is_platform_operator', true),
            })
            ->when($filters['flag'] ?? null, fn (Builder $q, string $flag) => match ($flag) {
                'minor' => $q->where(fn ($w) => $w
                    ->whereIn('id', DB::table('safety_guardian_links')->where('status', 'active')->select('learner_id'))
                    ->orWhereIn('id', DB::table('safety_age_records')->where('birth_date', '>', now()->subYears(18)->toDateString())->select('user_id'))
                    ->orWhereIn('id', DB::table('learner_profiles')->whereNotNull('birth_month')->where('birth_month', '>', now()->subYears(18)->toDateString())->select('user_id'))),
                'suspended' => $q->whereIn('id', AccountRestriction::query()->inEffect()->where('kind', AccountRestriction::SUSPENSION)->select('user_id')),
                'warned' => $q->whereIn('id', AccountRestriction::query()->where('kind', AccountRestriction::WARNING)->select('user_id')),
            })
            ->orderByDesc('id')
            ->paginate(25)->withQueryString();

        $standing->prime($users->getCollection()); // a handful of queries for the page, not ~9 per person (D-1604)
        $tutors = TutorProfile::query()->whereIn('user_id', $users->pluck('id'))->pluck('status', 'user_id');
        $guardians = GuardianLink::query()->active()->whereIn('guardian_id', $users->pluck('id'))->pluck('guardian_id')->flip();

        return view('ops.users.index', [
            'users' => $users,
            'filters' => $filters,
            'rows' => $users->getCollection()->map(fn (User $u) => [
                'user' => $u,
                'account' => $standing->summary($u),
                'tutor' => $tutors[$u->id] ?? null,
                'guardian' => isset($guardians[$u->id]),
            ]),
        ]);
    }

    public function show(Request $request, User $user, AccountStanding $standing): View
    {
        Audit::record($request->user(), 'ops.account.viewed', $user);

        return view('ops.users.show', [
            'person' => $user,
            'account' => $standing->summary($user),
            'tutor' => TutorProfile::query()->where('user_id', $user->id)->first(),
            'learnerProfile' => DB::table('learner_profiles')
                ->leftJoin('curricula', 'curricula.id', '=', 'learner_profiles.curriculum_id')
                ->leftJoin('academic_levels', 'academic_levels.id', '=', 'learner_profiles.academic_level_id')
                ->leftJoin('countries', 'countries.id', '=', 'learner_profiles.country_id')
                ->where('learner_profiles.user_id', $user->id)
                ->first(['countries.name as country', 'curricula.name as curriculum', 'academic_levels.name as level']),
            'asLearner' => GuardianLink::query()->with('guardian')->where('learner_id', $user->id)->orderByDesc('id')->get(),
            'asGuardian' => GuardianLink::query()->with('learner')->where('guardian_id', $user->id)->orderByDesc('id')->get(),
            'restrictions' => AccountRestriction::query()->where('user_id', $user->id)->orderByDesc('id')->limit(50)->get(),
            'actions' => ModerationAction::query()->where('target_user_id', $user->id)->orderByDesc('id')->limit(50)->get(),
            'reports' => Report::query()->where('reported_user_id', $user->id)->orderByDesc('id')->limit(20)->get(),
            'ageRecords' => AgeRecord::query()->where('user_id', $user->id)->orderByDesc('id')->get(),
            'audit' => $this->can(P::AUDIT_VIEW)
                ? AuditEntry::query()->where(fn ($q) => $q->where('actor_id', $user->id)->orWhere(fn ($w) => $w->where('subject_type', $user->getMorphClass())->where('subject_id', $user->id)))
                    ->orderByDesc('id')->limit(20)->get()
                : null,
            'roles' => $user->is_platform_operator ? $this->access()->roles($user) : null,
            'canRestrict' => $this->can(P::USERS_RESTRICT),
            'canMinors' => $this->can(P::MINORS_MANAGE),
            'canRoles' => $this->can(P::ROLES_MANAGE),
        ]);
    }

    public function restrict(Request $request, User $user, Moderation $moderation): RedirectResponse
    {
        $data = $request->validate([
            'action' => ['required', Rule::in(['warn', 'suspend', 'unsuspend'])],
            'reason' => ['required', 'string', 'max:500'],
            'days' => ['nullable', 'integer', 'min:1', 'max:3650'],
        ]);

        $moderation->act($request->user(), $data['action'], null, null, $user, $data['reason'], array_filter(['days' => $data['days'] ?? null]));

        return $this->done(match ($data['action']) {
            'warn' => 'Warning recorded and sent.',
            'suspend' => 'Account suspended. Every gated action is refused until it ends or is lifted.',
            'unsuspend' => 'Suspension lifted.',
        });
    }

    /** An operator-verified date of birth (it outranks self-declared and guardian-declared dates). Same rule as safety's own endpoint. */
    public function age(Request $request, User $user, Messenger $messenger): RedirectResponse
    {
        $data = $request->validate([
            'birth_date' => ['required', 'date_format:Y-m-d', 'before:today', 'after:1900-01-01'],
            'note' => ['required', 'string', 'max:255'],
        ]);

        AgeRecord::create(['user_id' => $user->id, 'birth_date' => $data['birth_date'], 'source' => 'operator_verified', 'recorded_by_id' => $request->user()->id, 'note' => $data['note']]);
        $messenger->syncGuardians($user);
        Audit::record($request->user(), 'safety.age.verified', $user);

        return $this->done('Verified date of birth recorded.');
    }

    public function revokeLink(Request $request, GuardianLink $link, Guardians $guardians): RedirectResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:255']]);
        abort_unless($link->status === GuardianLink::ACTIVE, 404);

        $guardians->revoke($link, $request->user(), $data['reason']);

        return $this->done('Guardian link removed and consent withdrawn. The learner is limited until another guardian consents.');
    }
}
