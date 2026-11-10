<?php

namespace App\Domain\Ai\Http\Controllers;

use App\Domain\Ai\Models\AiInteraction;
use App\Domain\Ai\Services\NeedAssistant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** "Describe what you need": the deterministic parser, an optional AI fallback, and the confirmation step. */
class NeedController extends AiController
{
    public function describe(Request $request, NeedAssistant $needs): JsonResponse|View
    {
        $data = $request->validate([
            'text' => ['required', 'string', 'max:500'],
            'use_ai' => ['nullable', 'boolean'],
        ]);
        $result = $needs->describe($request->user(), $data['text'], (bool) ($data['use_ai'] ?? false));

        if ($this->wantsJson($request)) {
            return response()->json(['data' => $result]);
        }

        return view('ai::need', ['result' => $result, 'text' => $data['text']]);
    }

    public function form(): View
    {
        return view('ai::need', ['result' => null, 'text' => '']);
    }

    public function confirm(Request $request, AiInteraction $interaction, NeedAssistant $needs): JsonResponse|RedirectResponse
    {
        $data = $request->validate([
            'subject_id' => ['required', 'integer'],
            'level_id' => ['nullable', 'integer'],
            'languages' => ['nullable', 'array', 'max:3'],
            'languages.*' => ['string', 'max:20'],
        ]);
        $url = $needs->confirm($request->user(), $interaction, $data);

        return $this->wantsJson($request) ? response()->json(['data' => ['confirmed' => true, 'match_url' => $url]]) : redirect()->to($url);
    }
}
