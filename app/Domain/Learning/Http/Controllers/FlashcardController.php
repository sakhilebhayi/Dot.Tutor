<?php

namespace App\Domain\Learning\Http\Controllers;

use App\Domain\Learning\Contracts\Seams\SafeguardingCheck;
use App\Domain\Learning\Models\Flashcard;
use App\Domain\Learning\Models\FlashcardSet;
use App\Domain\Learning\Services\SpacedRepetition;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Flashcard sets (private or shared) and spaced review. */
class FlashcardController extends LearningController
{
    public function index(Request $request): JsonResponse
    {
        return response()->json(['data' => [
            'mine' => FlashcardSet::query()->where('owner_id', $request->user()->id)->withCount('cards')->orderBy('id')->get(),
            'shared' => FlashcardSet::query()->where('is_shared', true)->where('owner_id', '!=', $request->user()->id)->withCount('cards')->latest('id')->limit(50)->get(),
        ]]);
    }

    public function store(Request $request, SafeguardingCheck $safeguarding): JsonResponse
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:160'],
            'subject_id' => ['nullable', 'integer', 'exists:subjects,id'],
            'topic' => ['nullable', 'string', 'max:120'],
            'language' => ['nullable', 'string', 'max:20'],
            'is_shared' => ['nullable', 'boolean'],
            'cards' => ['required', 'array', 'min:1', 'max:500'],
            'cards.*.front' => ['required', 'string', 'max:2000'],
            'cards.*.back' => ['required', 'string', 'max:2000'],
        ]);
        if (! empty($data['is_shared']) && ! ($decision = $safeguarding->allows($request->user(), 'publish'))->allowed) {
            throw ValidationException::withMessages(['is_shared' => $decision->reason ?? 'Sharing is not allowed for this account.']);
        }
        $set = DB::transaction(function () use ($request, $data) {
            $set = FlashcardSet::create([
                'owner_id' => $request->user()->id,
                'title' => $data['title'],
                'subject_id' => $data['subject_id'] ?? null,
                'topic' => $data['topic'] ?? null,
                'language' => $data['language'] ?? 'en',
                'is_shared' => (bool) ($data['is_shared'] ?? false),
            ]);
            foreach (array_values($data['cards']) as $i => $card) {
                $set->cards()->create(['front' => $card['front'], 'back' => $card['back'], 'position' => $i]);
            }

            return $set;
        });

        return response()->json(['data' => $set->load('cards')], 201);
    }

    public function due(Request $request, FlashcardSet $set, SpacedRepetition $srs): JsonResponse
    {
        abort_unless($set->owner_id === $request->user()->id || $set->is_shared, 404);

        return response()->json(['data' => [
            'set' => $set->only(['id', 'title', 'topic']),
            'due' => $srs->due($request->user(), $set)->map(fn (Flashcard $c) => $c->only(['id', 'front', 'back']))->all(),
        ]]);
    }

    public function review(Request $request, Flashcard $card, SpacedRepetition $srs): JsonResponse
    {
        abort_unless($card->set->owner_id === $request->user()->id || $card->set->is_shared, 404);
        $grade = (int) $request->validate(['grade' => ['required', 'integer', 'min:0', 'max:5']])['grade'];
        $state = $srs->review($request->user(), $card, $grade);

        return response()->json(['data' => [
            'card_id' => $card->id,
            'interval_days' => $state->interval_days,
            'repetitions' => $state->repetitions,
            'ease' => $state->ease,
            'due_at' => $state->due_at->toIso8601String(),
        ]]);
    }
}
