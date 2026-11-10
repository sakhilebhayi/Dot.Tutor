<?php

namespace App\Domain\Ai\Http\Controllers;

use App\Domain\Ai\Models\AiInteraction;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/** Base for AI controllers: one action answers the JSON API and the web form, decided per request. */
abstract class AiController extends Controller
{
    private const STATUS_BY_CODE = [
        'not_configured' => 503,
        'account_suspended' => 403, 'guardian_consent_required' => 403, 'band_disabled' => 403, 'not_a_tutor' => 403,
        'daily_limit' => 429, 'daily_cost_limit' => 429, 'monthly_budget' => 429,
        'not_found' => 404, 'not_your_attempt' => 404, 'not_your_bank' => 404, 'not_your_course' => 404,
    ];

    protected function wantsJson(Request $request): bool
    {
        return $request->is('api/*') || $request->expectsJson();
    }

    protected function error(string $code, string $message, int $status): JsonResponse
    {
        return response()->json(['error' => $code, 'message' => $message], $status);
    }

    /** JSON status for an interaction: 201 answered, 4xx/5xx refusals with {error, message, data}. */
    protected function interactionResponse(AiInteraction $interaction, array $extra = []): JsonResponse
    {
        $data = $interaction->exists ? $interaction->present() : [
            'id' => null, 'task' => $interaction->task, 'status' => $interaction->status, 'refusal_code' => $interaction->refusal_code,
            'message' => $interaction->statusMessage(), 'label' => AiInteraction::LABEL,
        ];
        if ($interaction->answered()) {
            return response()->json(['data' => $data + $extra], 201);
        }
        $code = (string) ($interaction->refusal_code ?? $interaction->status);
        $status = match (true) {
            isset(self::STATUS_BY_CODE[$code]) => self::STATUS_BY_CODE[$code],
            $interaction->status === 'failed' => 502,
            default => 422,
        };

        return response()->json(['error' => $code === 'not_configured' ? 'ai_not_configured' : $code, 'message' => $interaction->statusMessage(), 'data' => $data + $extra], $status);
    }

    protected function backWith(AiInteraction $interaction, string $route): RedirectResponse
    {
        if ($interaction->exists) {
            return redirect()->route($route, $interaction);
        }

        return back()->withInput()->with('error', $interaction->statusMessage());
    }
}
