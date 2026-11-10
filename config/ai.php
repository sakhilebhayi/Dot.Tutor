<?php

/*
|--------------------------------------------------------------------------
| R-47 AI learning assistant (owner: ai agent, decisions D-900 onward)
|--------------------------------------------------------------------------
|
| Off by default. Nothing is sent anywhere until AI_ENABLED=true AND an
| ANTHROPIC_API_KEY is set; until then every AI screen says "AI is not
| configured" and no request leaves the server. See docs/R47_AI.md.
|
*/

return [

    'enabled' => (bool) env('AI_ENABLED', false),

    'anthropic' => [
        'api_key' => env('ANTHROPIC_API_KEY'),

        // Fixed base URL: the SDK would otherwise read ANTHROPIC_BASE_URL from the environment.
        'base_url' => 'https://api.anthropic.com',

        // Default model for every task (D-902). AI_MODEL_LIGHT is used for the cheap tasks (translation,
        // need parsing); it defaults to the same model at a lower effort. The owner may set it to
        // claude-haiku-5-5 to cut the cost of those tasks (prices below).
        'model' => env('AI_MODEL', 'claude-opus-5-5'),
        'model_light' => env('AI_MODEL_LIGHT', env('AI_MODEL', 'claude-opus-5-5')),

        // Server-side refusal fallback (beta server-side-fallback-2026-07-01, fallbacks: "default"). When the
        // model declines a request for policy reasons, Anthropic retries it on its default fallback model.
        'refusal_fallback' => (bool) env('AI_REFUSAL_FALLBACK', true),

        // A web request waits for the answer (no queue worker on the shared host), so keep this short.
        'timeout_seconds' => (float) env('AI_TIMEOUT_SECONDS', 60),
        'max_retries' => (int) env('AI_MAX_RETRIES', 1),
    ],

    // Effort per tier. Claude Opus 5.5 always thinks; effort is the only depth control (its default is medium).
    'effort' => [
        'standard' => env('AI_EFFORT', 'medium'),
        'light' => env('AI_EFFORT_LIGHT', 'low'),
    ],

    'max_tokens' => [
        'standard' => 16000,
        'light' => 8000,
    ],

    /*
    | Anthropic first-party prices in US cents per million tokens (claude-api skill table, cached 2026-10-06).
    | Used only to enforce the cost limits below; the Anthropic Console bill is the source of truth. A model
    | missing from this table is charged at the most expensive row.
    */
    'prices_cents_per_mtok' => [
        'claude-opus-5-5' => ['input' => 400, 'output' => 2000],
        'claude-opus-5' => ['input' => 500, 'output' => 2500],
        'claude-opus-4-8' => ['input' => 500, 'output' => 2500],
        'claude-sonnet-5-5' => ['input' => 200, 'output' => 1000],
        'claude-haiku-5-5' => ['input' => 10, 'output' => 50],
        'claude-fable-5-1' => ['input' => 1000, 'output' => 5000],
    ],

    /*
    | Limits. Requests that reached the model count; refused-before-sending requests do not. Costs are in
    | micro US dollars (1 000 000 = $1). A day is the application timezone's calendar day.
    */
    'limits' => [
        'per_minute' => (int) env('AI_PER_MINUTE', 6),
        'learner' => [
            'child' => ['daily_requests' => 15, 'daily_cost_micro_usd' => 250_000],
            'teen' => ['daily_requests' => 30, 'daily_cost_micro_usd' => 500_000],
            'adult' => ['daily_requests' => 50, 'daily_cost_micro_usd' => 750_000],
        ],
        'tutor' => ['daily_requests' => 60, 'daily_cost_micro_usd' => 1_500_000],
        'monthly_budget_micro_usd' => (int) env('AI_MONTHLY_BUDGET_USD', 100) * 1_000_000,
    ],

    // How the assistant treats someone whose age is not known (D-905): the most protected band.
    'unknown_age_band' => env('AI_UNKNOWN_AGE_BAND', 'child'),

    // Which bands may use the assistant at all. Minors also need guardian consent (safety AccountStanding).
    'bands_enabled' => ['child' => true, 'teen' => true, 'adult' => true],

    // Safeguarding log retention (days). Reported or flagged interactions are kept longer as evidence.
    'retention_days' => (int) env('AI_LOG_RETENTION_DAYS', 180),
    'evidence_retention_days' => (int) env('AI_EVIDENCE_RETENTION_DAYS', 730),

    'max_input_chars' => 4000,

    // Library files the assistant may read for "help me with this resource" (PDF and images only).
    'resource' => [
        'max_pdf_kb' => 10240,
        'max_image_kb' => 5120,
    ],

    // Text to translate in one request.
    'translation_max_chars' => 12000,

    // Prompt version: bump when the guardrail prompts change, so the log says which rules produced an answer.
    'prompt_version' => '2026-11-10.1',
];
