<?php

namespace App\Domain\Teach\Http\Controllers;

use App\Domain\Academic\Models\AcademicLevel;
use App\Domain\Learning\Contracts\Seams\AcademicScopeLookup;
use App\Domain\Learning\Enums\AssessmentKind;
use App\Domain\Learning\Enums\CompletionRule;
use App\Domain\Learning\Enums\CourseStatus;
use App\Domain\Learning\Enums\LessonType;
use App\Domain\Learning\Enums\MaterialPermission;
use App\Domain\Learning\Http\Controllers\AssessmentController;
use App\Domain\Learning\Http\Controllers\CourseStudioController;
use App\Domain\Learning\Models\Assessment;
use App\Domain\Learning\Models\Bundle;
use App\Domain\Learning\Models\Course;
use App\Domain\Learning\Services\CoursePublisher;
use App\Domain\Learning\Services\ProgressService;
use App\Domain\Learning\Support\CoursePresenter;
use App\Domain\Money\Models\OrderLine;
use App\Domain\Money\Money;
use App\Domain\Money\Services\CommissionRules;
use App\Domain\Teach\Services\AcademicOptions;
use App\Domain\Teach\Support\TutorContext;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * The courses studio (brief section 8 and the course creator journey in 25), made for someone who has never
 * published a course: a guided set of steps over the learning domain's studio. Structure, uploads, materials,
 * publishing and archiving post straight to the learning routes (which check ownership and answer back to this
 * page); details and pricing go through a thin wrapper that turns a level into its curriculum and rands into cents.
 */
class CoursesController extends Controller
{
    public const TABS = ['basics', 'outcomes', 'structure', 'quizzes', 'price', 'publish', 'learners'];

    public function index(Request $request): View
    {
        $profile = TutorContext::profile($request);
        $user = $request->user();
        $courses = Course::query()->where('owner_id', $user->id)->withCount([
            'enrolments as active_enrolments' => fn ($q) => $q->where('status', 'active'),
            'lessons',
        ])->latest('id')->get();

        $sales = OrderLine::query()->where('seller_id', $user->id)->where('purchasable_type', 'course')
            ->whereHas('order', fn ($q) => $q->whereIn('status', ['paid', 'partially_refunded']))
            ->selectRaw('purchasable_id, count(*) as n, sum(seller_net_minor) as net')->groupBy('purchasable_id')->get()->keyBy('purchasable_id');

        return view('teach.courses', [
            'profile' => $profile,
            'courses' => $courses,
            'sales' => $sales,
            'bundles' => Bundle::query()->where('owner_id', $user->id)->with('courses:id,title')->latest('id')->get(),
            'publishedCourses' => $courses->where('status', CourseStatus::Published),
        ]);
    }

    public function create(Request $request, AcademicOptions $options): View
    {
        $profile = TutorContext::profile($request);

        return view('teach.course-new', [
            'profile' => $profile,
            'subjects' => $options->subjects(),
            'levels' => $options->levels(),
            'languages' => $options->languages(),
        ]);
    }

    public function store(Request $request, AcademicScopeLookup $academic): RedirectResponse
    {
        TutorContext::profile($request);
        $data = $request->validate([
            'title' => ['required', 'string', 'max:160'],
            'description' => ['nullable', 'string', 'max:5000'],
            'subject_id' => ['nullable', 'integer'],
            'level_id' => ['nullable', 'integer'],
            'original_locale' => ['required', 'string', 'max:20', 'regex:/^[a-z]{2,3}(-[A-Za-z0-9]{2,8})*$/'],
        ]);
        $data['curriculum_id'] = $this->curriculumFor($data['level_id'] ?? null);
        $errors = $academic->validate($data['curriculum_id'], $data['level_id'] ?? null, $data['subject_id'] ?? null);
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        $course = Course::create([
            'owner_id' => $request->user()->id,
            'slug' => Str::slug(Str::limit($data['title'], 60, '')).'-'.Str::lower(Str::random(6)),
            'title' => $data['title'],
            'description' => $data['description'] ?? null,
            'subject_id' => $data['subject_id'] ?? null,
            'curriculum_id' => $data['curriculum_id'],
            'level_id' => $data['level_id'] ?? null,
            'original_locale' => $data['original_locale'],
            'currency' => config('tutor.currency', 'ZAR'),
            'status' => CourseStatus::Draft,
        ]);

        return redirect()->route('teach.courses.show', [$course, 'tab' => 'outcomes'])
            ->with('success', 'Draft course created. Nobody sees it until you publish.');
    }

    public function show(Request $request, int $course, CoursePresenter $presenter, CoursePublisher $publisher, ProgressService $progress, AcademicOptions $options, CommissionRules $rules): View
    {
        TutorContext::profile($request);
        $model = Course::query()->findOrFail($course);
        Gate::authorize('manage', $model);
        $tab = in_array($request->query('tab'), self::TABS, true) ? $request->query('tab') : 'basics';
        $draft = $presenter->draft($model);
        $price = (int) ($model->price_minor ?? 0);

        return view('teach.course', [
            'course' => $model,
            'draft' => $draft,
            'tab' => $tab,
            'problems' => $publisher->problems($model, $request->user()),
            'unpublished' => $model->current_version_id ? $publisher->hasUnpublishedChanges($model) : null,
            'subjects' => $options->subjects(),
            'levels' => $options->levels(),
            'languages' => $options->languages(),
            'lessonTypes' => LessonType::cases(),
            'permissions' => MaterialPermission::cases(),
            'completionRules' => CompletionRule::cases(),
            'quizzes' => Assessment::query()->where('owner_id', $request->user()->id)->where('course_id', $model->id)
                ->where('kind', AssessmentKind::CourseQuiz->value)->withCount('questions')->get(),
            'split' => OfferingsController::splitPayload($rules->split(Money::of($price, $model->currency), 'course')),
            'learners' => $tab === 'learners' ? $model->enrolments()->with(['user:id,name', 'version'])->where('status', 'active')->orderBy('id')->get()
                ->map(fn ($e) => ['name' => TutorContext::learnerName($e->user), 'version' => $e->version?->number, 'enrolled_at' => $e->created_at, ...$progress->summary($e)]) : collect(),
        ]);
    }

    /** Details and price go through here: a chosen level brings its curriculum; a price in rands becomes cents. */
    public function details(Request $request, int $course): RedirectResponse|JsonResponse
    {
        TutorContext::profile($request);
        $model = Course::query()->findOrFail($course);
        Gate::authorize('manage', $model);

        if ($request->has('level_id')) {
            $level = $request->input('level_id') !== null && $request->input('level_id') !== '' ? (int) $request->input('level_id') : null;
            $request->merge(['level_id' => $level, 'curriculum_id' => $this->curriculumFor($level)]);
        }
        if ($request->has('subject_id') && $request->input('subject_id') === '') {
            $request->merge(['subject_id' => null]);
        }
        if ($request->has('price')) {
            $raw = trim((string) $request->input('price'));
            if ($raw !== '' && ! preg_match('/^\d{1,7}([.,]\d{1,2})?$/', $raw)) {
                throw ValidationException::withMessages(['price' => 'Write the price as a number, for example 350 or 350.00.']);
            }
            $request->merge(['price_minor' => $raw === '' ? null : Money::fromDecimal(str_replace(',', '.', $raw))->minor]);
        }
        foreach (['workload_minutes', 'completion_rule'] as $nullable) {
            if ($request->has($nullable) && $request->input($nullable) === '') {
                $request->merge([$nullable => null]);
            }
        }

        app()->call([app(CourseStudioController::class), 'update'], ['request' => $request, 'course' => $model]);

        $next = $request->input('next');

        return redirect()->route('teach.courses.show', [$model, 'tab' => in_array($next, self::TABS, true) ? $next : $request->input('tab', 'basics')])
            ->with('success', 'Saved.');
    }

    public function storeQuiz(Request $request, int $course): RedirectResponse
    {
        TutorContext::profile($request);
        $model = Course::query()->findOrFail($course);
        Gate::authorize('manage', $model);
        $request->merge(['kind' => AssessmentKind::CourseQuiz->value, 'course_id' => $model->id]);
        $response = app()->call([app(AssessmentController::class), 'store'], ['request' => $request]);
        $id = $response instanceof JsonResponse ? ($response->getData(true)['data']['id'] ?? null) : null;

        return $id
            ? redirect()->route('teach.materials.assessments.show', $id)->with('success', 'Quiz created. Add questions from a question bank, then put it in a lesson.')
            : back()->with('error', 'The quiz could not be created.');
    }

    public function storeBundle(Request $request): RedirectResponse
    {
        TutorContext::profile($request);
        $raw = trim((string) $request->input('price', ''));
        if (! preg_match('/^\d{1,7}([.,]\d{1,2})?$/', $raw)) {
            throw ValidationException::withMessages(['price' => 'Write the bundle price as a number, for example 500.']);
        }
        $request->merge(['price_minor' => Money::fromDecimal(str_replace(',', '.', $raw))->minor]);
        app()->call([app(CourseStudioController::class), 'storeBundle'], ['request' => $request]);

        return back()->with('success', 'Bundle created. Buyers get every course in it.');
    }

    private function curriculumFor(?int $levelId): ?int
    {
        if ($levelId === null) {
            return null;
        }
        $curriculum = AcademicLevel::query()->whereKey($levelId)->value('curriculum_id');

        return $curriculum === null ? null : (int) $curriculum;
    }
}
