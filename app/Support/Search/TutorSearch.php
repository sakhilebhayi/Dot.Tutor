<?php

namespace App\Support\Search;

use App\Domain\Learning\Enums\CourseStatus;
use App\Domain\Learning\Enums\ResourceStatus;
use App\Domain\Learning\Models\Assessment;
use App\Domain\Learning\Models\Course;
use App\Domain\Learning\Models\Enrolment;
use App\Domain\Learning\Models\LibraryResource;
use App\Domain\Learning\Models\QuestionBank;
use App\Domain\Marketplace\Models\TutorOffering;
use App\Domain\Marketplace\Models\TutorSessionParticipant;
use App\Domain\Money\Models\Order;
use App\Domain\Ops\Access\OpsAccess;
use App\Domain\Ops\Access\OpsPermission as P;
use App\Domain\Ops\Access\RoutePermissions;
use App\Domain\Safety\Models\GuardianApproval;
use App\Domain\Safety\Models\GuardianLink;
use App\Domain\Safety\Models\Incident;
use App\Domain\Student\Services\LearnerContext;
use App\Domain\Teach\Services\StudentBook;
use App\Enums\SessionStatus;
use App\Enums\TutorProfileStatus;
use App\Models\Subject;
use App\Models\TutorProfile;
use App\Models\TutorSession;
use App\Models\User;
use App\View\ShellNavigation;
use Illuminate\Contracts\Database\Query\Builder as QueryBuilder;
use Illuminate\Database\Eloquent\Builder;

/**
 * The header search (R-52). Dot.Tutor has four rooms with four kinds of person in them, so a search is ALWAYS made in one scope and
 * only ever reads what that person may already open from the screens of that scope:
 *
 *   learn     a learner: the tutors anyone can browse, their OWN lessons and courses, the published courses and study library
 *   guardian  a guardian: the children they are actively linked to, and the approvals waiting for them
 *   teach     a tutor: their OWN students (the people who booked or enrolled with them), lessons and requests, offerings, courses, materials
 *   ops       an operator: users, tutor applications, orders, incidents and subjects, each group only with the permission its screen needs
 *
 * The scope is a request value, so it is never trusted: {@see resolveScope()} checks it against what the signed-in person really is (a
 * tutor profile, an active guardian link, the platform-operator flag) and falls back to `learn`, which only ever shows public and own data.
 * Every query below starts from the person (`student_id = me`, `tutor_profile_id = mine`, `guardian_id = me`) or from a published state,
 * never from the search text, and the text is only ever a LIKE parameter (escaped, bound, never concatenated). A learner can never
 * reach another learner's data from any scope; a guardian sees only their linked children; a minor's name is shown to a tutor only
 * when that child is already in the tutor's own student book, the same rule the Students screen applies.
 */
final class TutorSearch
{
    public const MIN_LENGTH = 2;

    public const MAX_LENGTH = 80;

    public const SCOPES = ['learn' => 'Learning', 'guardian' => 'Guardian', 'teach' => 'Teaching', 'ops' => 'Operations'];

    /** scope => the shell navigation (config/tutor_nav.php) its "Go to" pages come from */
    private const NAV = ['learn' => 'learner', 'guardian' => 'guardian', 'teach' => 'workspace', 'ops' => 'operations'];

    public function __construct(
        private readonly StudentBook $students,
        private readonly OpsAccess $ops,
        private readonly ShellNavigation $navigation,
    ) {}

    public static function isSearchable(string $q): bool
    {
        return mb_strlen(trim($q)) >= self::MIN_LENGTH;
    }

    /** @return list<string> the words of the query, longest first (for marking the match) */
    public static function words(string $q): array
    {
        $words = array_values(array_unique(array_filter(preg_split('/\s+/u', trim(mb_substr($q, 0, self::MAX_LENGTH))) ?: [])));
        usort($words, fn (string $a, string $b) => mb_strlen($b) <=> mb_strlen($a));

        return $words;
    }

    /** @return list<string> the scopes this person may search in, `learn` always first */
    public function scopesFor(User $user): array
    {
        $scopes = ['learn'];
        if (class_exists(GuardianLink::class) && GuardianLink::query()->where('guardian_id', $user->id)->active()->exists()) {
            $scopes[] = 'guardian';
        }
        if ($user->tutorProfile()->exists()) {
            $scopes[] = 'teach';
        }
        if ($this->ops->isOperator($user)) {
            $scopes[] = 'ops';
        }

        return $scopes;
    }

    /** The requested scope when this person may use it, otherwise `learn`. */
    public function resolveScope(User $user, ?string $requested): string
    {
        return $requested !== null && in_array($requested, $this->scopesFor($user), true) ? $requested : 'learn';
    }

    /**
     * @return array<string, list<SearchHit>> group label => hits (empty groups are left out)
     */
    public function search(User $user, string $scope, string $q, int $per = 4): array
    {
        $q = trim(mb_substr($q, 0, self::MAX_LENGTH));
        if (! self::isSearchable($q)) {
            return [];
        }

        $scope = $this->resolveScope($user, $scope);
        $groups = match ($scope) {
            'guardian' => $this->guardian($user, $q, $per),
            'teach' => $this->teach($user, $q, $per),
            'ops' => $this->operations($user, $q, $per),
            default => $this->learn($user, $q, $per),
        };

        $pages = $this->pages($user, $scope, $q, $per);
        if ($pages !== []) {
            $groups['Pages'] = $pages;
        }

        return array_filter($groups, fn (array $hits) => $hits !== []);
    }

    /**
     * The shell's own destinations, optionally those whose name contains the text. An operator is only shown the ones their role can open.
     *
     * @return list<SearchHit>
     */
    public function pages(User $user, string $scope, ?string $q = null, int $limit = 6): array
    {
        $scope = $this->resolveScope($user, $scope);
        $needle = $q === null ? null : mb_strtolower(trim($q));
        $out = [];

        // A young learner's navigation has no Courses or Messages (the step relabels and drops items), so neither does their "Go to".
        $step = $scope === 'learn' ? app(LearnerContext::class)->step($user) : 'standard';

        foreach ($this->navigation->for(self::NAV[$scope], $step) as $section) {
            foreach ($section['items'] as $item) {
                if ($needle !== null && ! str_contains(mb_strtolower($item['label']), $needle)) {
                    continue;
                }
                if ($scope === 'ops' && ! $this->operatorMayOpen($user, $item['route'])) {
                    continue;
                }
                $out[] = new SearchHit($item['label'], $item['href'], null, 'Page');
                if (count($out) >= $limit) {
                    return $out;
                }
            }
        }

        return $out;
    }

    // ── learn ───────────────────────────────────────────────────────────────

    /** @return array<string, list<SearchHit>> */
    private function learn(User $user, string $q, int $per): array
    {
        $groups = [];

        // The tutors anyone signed in can browse (approved profiles, active subjects only), exactly as /tutors lists them.
        $tutors = TutorProfile::query()
            ->with(['user:id,name'])
            ->where('status', TutorProfileStatus::Approved->value)
            ->where(fn (Builder $w) => $w
                ->whereHas('user', fn (Builder $u) => self::like($u, 'users.name', $q))
                ->orWhere(fn (Builder $h) => self::like($h, 'tutor_profiles.headline', $q))
                ->orWhereHas('subjects', fn (Builder $s) => self::like($s->where('subjects.is_active', true), 'subjects.name', $q)))
            ->orderByDesc('rating')->orderBy('id')->limit($per)->get();
        $groups['Tutors'] = $tutors->map(fn (TutorProfile $t) => new SearchHit($t->displayName(), route('tutors.show', $t), null, 'Tutor'))->all();

        // Only lessons this person is a learner in (their own booking or a seat they hold), never anyone else's.
        $lessons = TutorSession::query()
            ->with(['subject:id,name', 'tutorProfile.user:id,name', 'offering:id,title'])
            ->where(fn (Builder $mine) => $mine->where('student_id', $user->id)
                ->orWhereIn('id', TutorSessionParticipant::query()->where('learner_id', $user->id)->select('tutor_session_id')))
            ->where(fn (Builder $w) => $w
                ->whereHas('subject', fn (Builder $s) => self::like($s, 'subjects.name', $q))
                ->orWhereHas('offering', fn (Builder $o) => self::like($o, 'tutor_offerings.title', $q))
                ->orWhereHas('tutorProfile.user', fn (Builder $u) => self::like($u, 'users.name', $q)))
            ->orderByDesc('starts_at')->orderByDesc('id')->limit($per)->get();
        $groups['My lessons'] = $lessons->map(fn (TutorSession $s) => $this->lessonHit($s, withTutor: true))->all();

        // Courses the person is enrolled in, then the published catalogue (a course appears once).
        $mine = Enrolment::query()->with('course:id,title')->where('user_id', $user->id)->whereIn('status', ['active', 'completed'])
            ->whereHas('course', fn (Builder $c) => self::like($c, 'learning_courses.title', $q))
            ->orderByDesc('updated_at')->limit($per)->get()->filter(fn (Enrolment $e) => $e->course !== null);
        $groups['My courses'] = $mine->map(fn (Enrolment $e) => new SearchHit($e->course->title, route('learning.courses.show', $e->course_id), null, 'Enrolled'))->values()->all();

        $enrolled = $mine->pluck('course_id')->all();
        $catalogue = Course::query()->where('status', CourseStatus::Published->value)->whereNotIn('id', $enrolled)
            ->where(fn (Builder $w) => self::like($w, 'learning_courses.title', $q)->orWhere(fn (Builder $d) => self::like($d, 'learning_courses.description', $q)))
            ->orderByDesc('published_at')->orderByDesc('id')->limit($per)->get(['id', 'title']);
        $groups['Courses'] = $catalogue->map(fn (Course $c) => new SearchHit($c->title, route('learning.courses.show', $c), null, 'Course'))->all();

        $library = LibraryResource::query()->where('status', ResourceStatus::Published->value)
            ->where(fn (Builder $w) => self::like($w, 'learning_resources.title', $q)->orWhere(fn (Builder $t) => self::like($t, 'learning_resources.topic', $q)))
            ->orderByDesc('id')->limit($per)->get(['id', 'title']);
        $groups['Study library'] = $library->map(fn (LibraryResource $r) => new SearchHit($r->title, route('learning.library.show', $r), null, 'Resource'))->all();

        return $groups;
    }

    // ── guardian ────────────────────────────────────────────────────────────

    /** @return array<string, list<SearchHit>> */
    private function guardian(User $user, string $q, int $per): array
    {
        $learnerIds = GuardianLink::query()->active()->where('guardian_id', $user->id)->pluck('learner_id')->all();
        if ($learnerIds === []) {
            return [];
        }

        $children = User::query()->whereIn('id', $learnerIds)->where(fn (Builder $w) => self::like($w, 'users.name', $q))
            ->orderBy('name')->limit($per)->get(['id', 'name']);

        $approvals = GuardianApproval::query()->with('learner:id,name')->whereIn('learner_id', $learnerIds)
            ->where('status', GuardianApproval::PENDING)->where('expires_at', '>', now())
            ->where(fn (Builder $w) => self::like($w, 'safety_guardian_approvals.summary', $q)
                ->orWhereHas('learner', fn (Builder $l) => self::like($l, 'users.name', $q)))
            ->orderBy('expires_at')->limit($per)->get();

        return [
            'Children' => $children->map(fn (User $c) => new SearchHit($c->name, route('guardian.child', $c->id), null, 'Child'))->all(),
            'Approvals' => $approvals->map(fn (GuardianApproval $a) => new SearchHit((string) $a->summary, route('guardian.approvals'), $a->learner?->name, 'Waiting'))->all(),
        ];
    }

    // ── teach ───────────────────────────────────────────────────────────────

    /** @return array<string, list<SearchHit>> */
    private function teach(User $user, string $q, int $per): array
    {
        $profile = $user->tutorProfile;
        if ($profile === null) {
            return [];
        }

        // Students: the tutor's own book only (people who booked, took a seat, bought a package or enrolled), by account name.
        $ids = $this->students->learnerIds($profile);
        $students = $ids === [] ? collect() : User::query()->whereIn('id', $ids)->where(fn (Builder $w) => self::like($w, 'users.name', $q))
            ->orderBy('name')->orderBy('id')->limit($per)->get(['id', 'name']);

        // Lessons and requests of THIS tutor; a request still waiting on a guardian is not shown (the Students screen hides it too).
        $lessons = TutorSession::query()->with(['student:id,name', 'subject:id,name', 'offering:id,title'])
            ->where('tutor_profile_id', $profile->id)->where('status', '!=', 'awaiting_guardian')
            ->where(fn (Builder $w) => $w
                ->whereHas('student', fn (Builder $s) => self::like($s, 'users.name', $q))
                ->orWhereHas('subject', fn (Builder $s) => self::like($s, 'subjects.name', $q))
                ->orWhereHas('offering', fn (Builder $o) => self::like($o, 'tutor_offerings.title', $q)))
            ->orderByDesc('starts_at')->orderByDesc('id')->limit($per)->get();

        $offerings = TutorOffering::query()->where('tutor_profile_id', $profile->id)->where(fn (Builder $w) => self::like($w, 'tutor_offerings.title', $q))
            ->orderBy('title')->limit($per)->get(['id', 'title', 'is_active']);

        $courses = Course::query()->where('owner_id', $user->id)->where(fn (Builder $w) => self::like($w, 'learning_courses.title', $q))
            ->orderByDesc('updated_at')->limit($per)->get(['id', 'title', 'status']);

        $banks = QuestionBank::query()->where('owner_id', $user->id)->where(fn (Builder $w) => self::like($w, 'learning_question_banks.title', $q))
            ->orderBy('title')->limit($per)->get(['id', 'title']);
        $assessments = Assessment::query()->where('owner_id', $user->id)->where(fn (Builder $w) => self::like($w, 'learning_assessments.title', $q))
            ->orderBy('title')->limit($per)->get(['id', 'title']);

        return [
            'Students' => $students->map(fn (User $s) => new SearchHit($s->name, route('teach.students.show', $s->id), null, 'Student'))->all(),
            'Lessons and requests' => $lessons->map(fn (TutorSession $s) => $this->lessonHit($s, withTutor: false))->all(),
            'Offerings' => $offerings->map(fn (TutorOffering $o) => new SearchHit($o->title, route('teach.offerings.edit', $o->id), null, $o->is_active ? 'Offering' : 'Archived'))->all(),
            'Courses' => $courses->map(fn (Course $c) => new SearchHit($c->title, route('teach.courses.show', $c->id), null, $c->status instanceof CourseStatus ? ucfirst($c->status->value) : 'Course'))->all(),
            'Materials' => $banks->map(fn (QuestionBank $b) => new SearchHit($b->title, route('teach.materials.banks.show', $b->id), null, 'Question bank'))
                ->merge($assessments->map(fn (Assessment $a) => new SearchHit($a->title, route('teach.materials.assessments.show', $a->id), null, 'Assessment')))
                ->take($per)->values()->all(),
        ];
    }

    // ── ops ─────────────────────────────────────────────────────────────────

    /** @return array<string, list<SearchHit>> */
    private function operations(User $user, string $q, int $per): array
    {
        if (! $this->ops->isOperator($user)) {
            return [];
        }

        $groups = [];

        if ($this->ops->can($user, P::USERS_VIEW)) {
            $users = User::query()->where(fn (Builder $w) => self::like($w, 'users.name', $q)->orWhere(fn (Builder $e) => self::like($e, 'users.email', $q)))
                ->orderByDesc('id')->limit($per)->get(['id', 'name', 'email', 'is_platform_operator']);
            $groups['People'] = $users->map(fn (User $u) => new SearchHit($u->name, route('ops.users.show', $u->id), $u->email, $u->is_platform_operator ? 'Operator' : 'User'))->all();
        }

        if ($this->ops->can($user, P::VERIFICATION_REVIEW)) {
            $tutors = TutorProfile::query()->with('user:id,name')
                ->where(fn (Builder $w) => $w->whereHas('user', fn (Builder $u) => self::like($u, 'users.name', $q))->orWhere(fn (Builder $h) => self::like($h, 'tutor_profiles.headline', $q)))
                ->orderByRaw("case when status = 'pending' then 0 else 1 end")->orderByDesc('id')->limit($per)->get();
            $groups['Tutor profiles'] = $tutors->map(fn (TutorProfile $t) => new SearchHit($t->displayName(), route('ops.verification.tutor', $t->id), null, ucfirst((string) $t->status)))->all();
        }

        if ($this->ops->can($user, P::FINANCE_VIEW)) {
            $orders = Order::query()->with('buyer:id,name')
                ->where(fn (Builder $w) => self::like($w, 'money_orders.uuid', $q)->orWhereHas('buyer', fn (Builder $b) => self::like($b, 'users.name', $q)))
                ->orderByDesc('id')->limit($per)->get();
            $groups['Orders'] = $orders->map(fn (Order $o) => new SearchHit('Order '.mb_substr((string) $o->uuid, 0, 8).($o->buyer ? ', '.$o->buyer->name : ''), route('ops.payments.order', $o), null, ucfirst((string) $o->status)))->all();
        }

        if ($this->ops->can($user, P::SAFEGUARDING_VIEW)) {
            $incidents = Incident::query()->where(fn (Builder $w) => self::like($w, 'safety_incidents.reference', $q)->orWhere(fn (Builder $t) => self::like($t, 'safety_incidents.title', $q)))
                ->orderByDesc('id')->limit($per)->get(['id', 'reference', 'title', 'severity']);
            $groups['Incidents'] = $incidents->map(fn (Incident $i) => new SearchHit((string) $i->title, route('ops.safeguarding.incidents.show', $i->id), (string) $i->reference, ucfirst((string) $i->severity)))->all();
        }

        if ($this->ops->can($user, P::ACADEMIC_MANAGE)) {
            $subjects = Subject::query()->where(fn (Builder $w) => self::like($w, 'subjects.name', $q))->orderBy('name')->limit($per)->get(['id', 'name', 'is_active']);
            $groups['Subjects'] = $subjects->map(fn (Subject $s) => new SearchHit($s->name, route('ops.subjects'), null, $s->is_active ? 'Subject' : 'Inactive'))->all();
        }

        return $groups;
    }

    // ── helpers ─────────────────────────────────────────────────────────────

    private function lessonHit(TutorSession $s, bool $withTutor): SearchHit
    {
        $what = $s->offering?->title ?: ($s->subject?->name ?: 'Lesson');
        $who = $withTutor ? $s->tutorProfile?->displayName() : $s->student?->name;
        $title = $who ? $what.($withTutor ? ' with ' : ' for ').$who : $what;
        $status = $s->statusEnum();
        $tag = $status === SessionStatus::Pending ? 'Requested' : ucfirst(str_replace('_', ' ', $status->value));

        return new SearchHit($title, route($withTutor ? 'learn.lessons.show' : 'sessions.show', $s->id), $s->starts_at?->format('j M Y'), $tag);
    }

    /** Whether an operator's role opens the page behind this route (the permission table the route guard itself reads). */
    private function operatorMayOpen(User $user, string $route): bool
    {
        foreach (RoutePermissions::for($route) as $permission) {
            if ($this->ops->can($user, $permission)) {
                return true;
            }
        }

        return false;
    }

    /**
     * `lower(cast(column as text)) like ? escape '!'` (the cast lets PostgreSQL search a uuid column; SQLite ignores it) with the text escaped (! % _), so the text is only ever a bound parameter and a typed % or _ matches itself.
     * Portable: the same SQL runs on SQLite and PostgreSQL. $column is always a literal from this file, never request input.
     *
     * @param  Builder|QueryBuilder  $query
     */
    private static function like($query, string $column, string $text): mixed
    {
        $pattern = '%'.strtr(mb_strtolower($text), ['!' => '!!', '%' => '!%', '_' => '!_']).'%';

        return $query->whereRaw('lower(cast('.$column.' as text)) like ? escape \'!\'', [$pattern]);
    }
}
