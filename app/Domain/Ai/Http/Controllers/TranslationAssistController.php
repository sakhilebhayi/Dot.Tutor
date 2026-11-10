<?php

namespace App\Domain\Ai\Http\Controllers;

use App\Domain\Academic\Models\Translation;
use App\Domain\Academic\Translations;
use App\Domain\Ai\Guardrails\LearnerContextResolver;
use App\Domain\Ai\Services\AiGateway;
use App\Domain\Ai\Services\TranslationAssistant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** Machine translation into the Translations store, the side-by-side view, and human review. */
class TranslationAssistController extends AiController
{
    public function store(Request $request, TranslationAssistant $assistant): JsonResponse|RedirectResponse
    {
        $data = $request->validate([
            'type' => ['required', 'string', 'max:60'],
            'id' => ['required', 'integer'],
            'field' => ['required', 'string', 'max:60'],
            'locale' => ['required', 'string', 'max:20'],
        ]);
        if (! LearnerContextResolver::isAnswerLanguage($data['locale'])) {
            return $this->wantsJson($request)
                ? $this->error('unknown_language', 'Choose one of the platform\'s written languages.', 422)
                : back()->with('error', 'Choose one of the platform\'s written languages.');
        }
        $model = $assistant->resolve($request->user(), $data['type'], (int) $data['id'], $data['field']);
        abort_if($model === null, 404);

        $result = $assistant->translate($request->user(), $model, $data['field'], $data['locale']);
        $route = ['type' => $data['type'], 'id' => $data['id'], 'field' => $data['field'], 'locale' => $data['locale']];

        if ($this->wantsJson($request)) {
            if ($result['side_by_side'] !== null) {
                return response()->json(['data' => $result['side_by_side'] + ['reused' => $result['reused'], 'interaction_id' => $result['interaction']?->id]], $result['reused'] ? 200 : 201);
            }

            return $this->interactionResponse($result['interaction'], ['detail' => $result['interaction']->context['rejected_detail'] ?? null]);
        }
        if ($result['side_by_side'] === null) {
            $interaction = $result['interaction'];

            return redirect()->route('ai.translations.show', $route)->with('error', trim($interaction->statusMessage().' '.($interaction->context['rejected_detail'] ?? '')));
        }

        return redirect()->route('ai.translations.show', $route)->with('success', $result['reused'] ? 'This translation already existed and is up to date.' : 'Machine translation saved. It is labelled as not reviewed.');
    }

    public function show(Request $request, string $type, int $id, string $field, TranslationAssistant $assistant, Translations $translations, AiGateway $gateway): JsonResponse|View
    {
        $model = $assistant->resolve($request->user(), $type, $id, $field);
        abort_if($model === null, 404);
        $locale = (string) $request->query('locale', '');
        $view = $translations->sideBySide($model, $field, $locale !== '' ? $locale : 'en');
        $translation = $locale !== '' ? $translations->get($model, $field, $locale) : null;
        $view['may_review'] = $translation !== null && $assistant->mayReview($request->user(), $model, $translation->locale);
        $view['translation_id'] = $translation?->id;

        if ($this->wantsJson($request)) {
            return response()->json(['data' => $view]);
        }

        return view('ai::translation', [
            'view' => $view, 'type' => $type, 'id' => $id, 'field' => $field, 'locale' => $locale,
            'languages' => LearnerContextResolver::answerLanguages(), 'configured' => $gateway->configured(),
        ]);
    }

    public function review(Request $request, Translation $translation, TranslationAssistant $assistant): JsonResponse|RedirectResponse
    {
        $data = $request->validate(['decision' => ['required', 'in:approved,rejected']]);
        $translation = $assistant->review($request->user(), $translation, $data['decision']);

        return $this->wantsJson($request)
            ? response()->json(['data' => ['id' => $translation->id, 'review_status' => $translation->review_status->value, 'reviewed_at' => $translation->reviewed_at?->toIso8601String()]])
            : back()->with('success', $data['decision'] === 'approved' ? 'Marked as reviewed.' : 'Translation rejected; it will not be shown.');
    }
}
