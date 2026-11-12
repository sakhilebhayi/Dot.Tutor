<?php

namespace App\Domain\Teach\Http\Controllers;

use App\Domain\Academic\Models\AcademicLevel;
use App\Domain\Learning\Enums\AssessmentKind;
use App\Domain\Learning\Enums\QuestionType;
use App\Domain\Learning\Enums\ResourceLicence;
use App\Domain\Learning\Enums\ResourceType;
use App\Domain\Learning\Http\Controllers\AssessmentController;
use App\Domain\Learning\Http\Controllers\LibraryController;
use App\Domain\Learning\Models\Assessment;
use App\Domain\Learning\Models\Assignment;
use App\Domain\Learning\Models\LibraryResource;
use App\Domain\Learning\Models\Question;
use App\Domain\Learning\Models\QuestionBank;
use App\Domain\Money\Models\OrderLine;
use App\Domain\Money\Money;
use App\Domain\Teach\Services\AcademicOptions;
use App\Domain\Teach\Services\StudentBook;
use App\Domain\Teach\Support\TutorContext;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Study materials and assessments (brief sections 8, 9 and 13) for the tutor: their library uploads with licence and
 * price, question banks, assessments and assigning them to their own students. Writes go through the learning
 * domain's controllers (called in-process), which own the rules: file checks, licence rules, answer keys, the
 * teaching relationship and the safeguarding check.
 */
class MaterialsController extends Controller
{
    public function index(Request $request, AcademicOptions $options): View
    {
        $profile = TutorContext::profile($request);
        $user = $request->user();
        $resources = LibraryResource::query()->where('owner_id', $user->id)->latest('id')->get();
        $sales = OrderLine::query()->where('seller_id', $user->id)->where('purchasable_type', 'resource')
            ->whereHas('order', fn ($q) => $q->whereIn('status', ['paid', 'partially_refunded']))
            ->selectRaw('purchasable_id, count(*) as n, sum(seller_net_minor) as net')->groupBy('purchasable_id')->get()->keyBy('purchasable_id');

        return view('teach.materials', [
            'profile' => $profile,
            'resources' => $resources,
            'sales' => $sales,
            'types' => ResourceType::cases(),
            'licences' => ResourceLicence::cases(),
            'subjects' => $options->subjects(),
            'levels' => $options->levels(),
            'languages' => $options->languages(),
        ]);
    }

    public function upload(Request $request): RedirectResponse
    {
        TutorContext::profile($request);
        $raw = trim((string) $request->input('price', ''));
        if ($raw !== '') {
            if (! preg_match('/^\d{1,7}([.,]\d{1,2})?$/', $raw)) {
                throw ValidationException::withMessages(['price' => 'Write the price as a number, for example 45 or 45.00.']);
            }
            $minor = Money::fromDecimal(str_replace(',', '.', $raw))->minor;
            $request->merge(['price_minor' => $minor, 'is_free' => $minor === 0 ? '1' : '0']);
        } else {
            $request->merge(['is_free' => '1', 'price_minor' => null]);
        }
        if ($request->filled('level_id')) {
            $request->merge(['curriculum_id' => AcademicLevel::query()->whereKey((int) $request->input('level_id'))->value('curriculum_id')]);
        }

        app()->call([app(LibraryController::class), 'store'], ['request' => $request]);
        $latest = LibraryResource::query()->where('owner_id', $request->user()->id)->latest('id')->first();

        return redirect()->route('teach.materials')->with('success', $latest?->status?->value === 'pending_review'
            ? 'Uploaded. A reviewer confirms the official source before anyone else sees it.'
            : 'Uploaded and published to the library.');
    }

    public function assessments(Request $request): View
    {
        $profile = TutorContext::profile($request);
        $user = $request->user();

        return view('teach.assessments', [
            'profile' => $profile,
            'banks' => QuestionBank::query()->where('owner_id', $user->id)->withCount(['questions' => fn ($q) => $q->whereNull('retired_at')])->orderBy('title')->get(),
            'assessments' => Assessment::query()->where('owner_id', $user->id)->with('course:id,title')
                ->withCount(['questions', 'assignments', 'attempts as submitted_attempts' => fn ($q) => $q->where('status', 'submitted')])->latest('id')->get(),
        ]);
    }

    public function storeBank(Request $request): RedirectResponse
    {
        TutorContext::profile($request);
        $response = app()->call([app(AssessmentController::class), 'storeBank'], ['request' => $request]);
        $id = $response instanceof JsonResponse ? ($response->getData(true)['data']['id'] ?? null) : null;

        return $id ? redirect()->route('teach.materials.banks.show', $id)->with('success', 'Question bank created. Add questions next.') : back();
    }

    public function bank(Request $request, int $bank): View
    {
        TutorContext::profile($request);
        $model = QuestionBank::query()->where('owner_id', $request->user()->id)->findOrFail($bank);

        return view('teach.bank', [
            'bank' => $model,
            'questions' => Question::query()->where('bank_id', $model->id)->orderByRaw('retired_at is not null')->orderBy('id')->get()
                ->each(fn (Question $q) => $q->makeVisible(['answer', 'explanation'])),
            'types' => QuestionType::cases(),
        ]);
    }

    /** The question form sends plain fields; they are shaped into the answer key the learning domain marks against. */
    public function storeQuestion(Request $request, int $bank): RedirectResponse
    {
        TutorContext::profile($request);
        $model = QuestionBank::query()->where('owner_id', $request->user()->id)->findOrFail($bank);
        $type = QuestionType::tryFrom((string) $request->input('type'));

        $options = collect(['A', 'B', 'C', 'D', 'E'])->map(fn ($k) => ['key' => $k, 'text' => trim((string) $request->input('option_'.$k, ''))])
            ->filter(fn ($o) => $o['text'] !== '')->values()->all();
        $answer = match ($type) {
            QuestionType::SingleChoice => ['key' => (string) $request->input('correct', '')],
            QuestionType::MultiSelect => ['keys' => array_values((array) $request->input('correct_many', []))],
            QuestionType::Numeric => ['value' => (string) $request->input('numeric_value', ''), 'tolerance' => (string) ($request->input('numeric_tolerance') ?: '0')],
            QuestionType::ShortText => ['accepted' => array_values(array_filter(array_map('trim', explode("\n", (string) $request->input('accepted', '')))))],
            default => [],
        };
        $request->merge(['options' => in_array($type, [QuestionType::SingleChoice, QuestionType::MultiSelect], true) ? $options : null, 'answer' => $answer]);

        app()->call([app(AssessmentController::class), 'storeQuestion'], ['request' => $request, 'bank' => $model]);

        return back()->with('success', 'Question added. The answer stays hidden from learners until they submit.');
    }

    public function retireQuestion(Request $request, int $question): RedirectResponse
    {
        TutorContext::profile($request);
        $model = Question::query()->whereHas('bank', fn ($q) => $q->where('owner_id', $request->user()->id))->findOrFail($question);
        app()->call([app(AssessmentController::class), 'retireQuestion'], ['request' => $request, 'question' => $model]);

        return back()->with('success', 'Question retired. Past attempts keep their marks.');
    }

    public function storeAssessment(Request $request): RedirectResponse
    {
        TutorContext::profile($request);
        $request->validate(['kind' => ['required', 'in:'.AssessmentKind::TutorAssessment->value.','.AssessmentKind::Practice->value]]);
        $response = app()->call([app(AssessmentController::class), 'store'], ['request' => $request]);
        $id = $response instanceof JsonResponse ? ($response->getData(true)['data']['id'] ?? null) : null;

        return $id ? redirect()->route('teach.materials.assessments.show', $id)->with('success', 'Assessment created. Add questions, then assign it.') : back();
    }

    public function assessment(Request $request, int $assessment, StudentBook $book): View
    {
        $profile = TutorContext::profile($request);
        $model = Assessment::query()->with('course:id,title')->findOrFail($assessment);
        Gate::authorize('manage', $model);
        $user = $request->user();
        $attached = $model->questions()->get();
        $students = $book->list($profile);

        return view('teach.assessment', [
            'assessment' => $model,
            'attached' => $attached,
            'available' => Question::query()->whereNull('retired_at')->whereHas('bank', fn ($q) => $q->where('owner_id', $user->id))
                ->whereNotIn('id', $attached->pluck('id'))->with('bank:id,title')->orderBy('bank_id')->orderBy('id')->limit(300)->get(),
            'students' => $students,
            'assignments' => Assignment::query()->where('assessment_id', $model->id)->with('learner:id,name')->get(),
            'attempts' => $model->attempts()->with('user:id,name')->where('status', 'submitted')->orderByDesc('submitted_at')->limit(100)->get(),
            'tz' => TutorContext::tz($profile),
        ]);
    }

    public function attach(Request $request, int $assessment): RedirectResponse
    {
        TutorContext::profile($request);
        $model = Assessment::query()->findOrFail($assessment);
        Gate::authorize('manage', $model);
        app()->call([app(AssessmentController::class), 'attachQuestions'], ['request' => $request, 'assessment' => $model]);

        return back()->with('success', 'Questions added.');
    }

    public function assign(Request $request, int $assessment): RedirectResponse
    {
        TutorContext::profile($request);
        $model = Assessment::query()->findOrFail($assessment);
        Gate::authorize('manage', $model);
        if ($request->filled('due_date')) {
            $request->merge(['due_at' => $request->input('due_date').' 23:59:00']);
        }
        $response = app()->call([app(AssessmentController::class), 'assign'], ['request' => $request, 'assessment' => $model]);

        if ($response instanceof JsonResponse && $response->getStatusCode() >= 400) {
            return back()->with('error', $response->getData(true)['message'] ?? 'This learner cannot be given this assessment.');
        }

        return back()->with('success', 'Assigned. The learner sees it in their practice list.');
    }
}
