<?php

namespace App\Domain\Student\Http\Controllers;

use App\Domain\Learning\Http\Controllers\FlashcardController;
use App\Domain\Learning\Models\Assessment;
use App\Domain\Learning\Models\Assignment;
use App\Domain\Learning\Models\Attempt;
use App\Domain\Learning\Models\Enrolment;
use App\Domain\Learning\Models\Flashcard;
use App\Domain\Learning\Models\FlashcardSet;
use App\Domain\Learning\Services\SpacedRepetition;
use App\Http\Controllers\Controller;
use App\Models\Subject;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Practice (D-1115): work tutors set, quizzes in enrolled courses, past attempts, and flashcards with spaced review.
 * Creating a set and grading a card run the learning domain's own FlashcardController actions (same validation,
 * safeguarding and spaced-repetition rules); this controller only turns their JSON answers into page redirects.
 */
class PracticeController extends Controller
{
    public function index(Request $request, SpacedRepetition $srs): View
    {
        $user = $request->user();

        $assignments = Assignment::query()->with('assessment')->where('learner_id', $user->id)
            ->orderByRaw('due_at is null')->orderBy('due_at')->orderBy('id')->limit(50)->get()
            ->filter(fn ($a) => $a->assessment !== null);

        $courseIds = Enrolment::query()->where('user_id', $user->id)->where('status', 'active')->pluck('course_id')->all();
        $quizzes = Assessment::query()->with('course:id,title')->whereIn('course_id', $courseIds ?: [0])->orderBy('id')->limit(50)->get();

        $attempts = Attempt::query()->with('assessment:id,title,kind')->where('user_id', $user->id)->where('status', 'submitted')
            ->orderByDesc('submitted_at')->orderByDesc('id')->limit(20)->get();
        $best = $attempts->groupBy('assessment_id')->map(fn ($rows) => $rows->first());

        $mine = FlashcardSet::query()->with('cards')->where('owner_id', $user->id)->withCount('cards')->orderBy('id')->get();
        $shared = FlashcardSet::query()->where('is_shared', true)->where('owner_id', '!=', $user->id)->withCount('cards')->latest('id')->limit(12)->get();

        return view('student.practice', [
            'assignments' => $assignments,
            'quizzes' => $quizzes,
            'attempts' => $attempts,
            'latestByAssessment' => $best,
            'mine' => $mine->map(fn (FlashcardSet $set) => ['set' => $set, 'due' => $srs->due($user, $set, 500)->count()]),
            'shared' => $shared,
            'subjects' => Subject::query()->where('is_active', true)->orderBy('name')->pluck('name', 'id'),
        ]);
    }

    public function storeSet(Request $request): RedirectResponse
    {
        // Two fields per card (card[i][front], card[i][back]; R-47 polish D-2021); empty rows are dropped. The older
        // "front | back" lines (cards_text) are still read. The learning action validates the cards themselves.
        $cards = collect((array) $request->input('card', []))
            ->filter(fn ($c) => is_array($c))
            ->map(fn (array $c) => ['front' => trim((string) ($c['front'] ?? '')), 'back' => trim((string) ($c['back'] ?? ''))])
            ->reject(fn (array $c) => $c['front'] === '' && $c['back'] === '')
            ->values();
        if ($cards->isEmpty() && $request->filled('cards_text')) {
            $lines = preg_split('/\r\n|\r|\n/', (string) $request->input('cards_text', ''));
            $cards = collect($lines)->map(fn ($line) => trim($line))->filter()->map(function ($line) {
                [$front, $back] = array_pad(preg_split('/\s*\|\s*/', $line, 2), 2, '');

                return ['front' => trim($front), 'back' => trim($back)];
            })->values();
        }
        $cards = $cards->all();
        $request->merge(['cards' => $cards, 'is_shared' => $request->boolean('is_shared')]);
        if ($request->input('subject_id') === '') {
            $request->merge(['subject_id' => null]);
        }

        $response = app()->call([app(FlashcardController::class), 'store'], ['request' => $request]);
        $id = $response->getData(true)['data']['id'] ?? null;

        return redirect()->to($id ? route('practice.cards.show', $id) : route('practice.index'))->with('status', 'Your cards are saved.');
    }

    public function review(Request $request, int $set, SpacedRepetition $srs): View
    {
        $set = FlashcardSet::query()->with('cards')->findOrFail($set);
        abort_unless($set->owner_id === $request->user()->id || $set->is_shared, 404);
        $due = $srs->due($request->user(), $set);

        return view('student.cards', ['set' => $set, 'card' => $due->first(), 'left' => $due->count()]);
    }

    public function grade(Request $request, int $set, int $card): RedirectResponse
    {
        $flashcard = Flashcard::query()->where('set_id', $set)->findOrFail($card);
        app()->call([app(FlashcardController::class), 'review'], ['request' => $request, 'card' => $flashcard]);

        return redirect()->route('practice.cards.show', $set);
    }
}
