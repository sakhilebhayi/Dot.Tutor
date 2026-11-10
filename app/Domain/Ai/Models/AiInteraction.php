<?php

namespace App\Domain\Ai\Models;

use App\Domain\Ai\Guardrails\Refusal;
use App\Domain\Safety\Contracts\Reportable;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * One request to the assistant and what came back: the safeguarding log (brief section 14). Text is encrypted at
 * rest. Rows are purged after config ai.retention_days; reported or flagged rows are kept as evidence for
 * ai.evidence_retention_days. Statuses: ok, incomplete, refused_by_model, refused (a guardrail refused before
 * anything was sent; refusal_code says which), failed, awaiting_confirmation and confirmed (AI-assisted need parsing).
 */
class AiInteraction extends Model implements Reportable
{
    public const LABEL = 'AI guidance';

    public const DISCLAIMER = 'AI guidance, not verified fact and not an official result. It can be wrong: check it with a tutor or your teacher.';

    protected $table = 'ai_interactions';

    protected $fillable = [
        'user_id', 'actor_role', 'task', 'age_band', 'age_known', 'mode', 'status', 'refusal_code', 'language', 'model',
        'prompt_version', 'request_text', 'prompt_text', 'output_text', 'output_data', 'context', 'input_tokens',
        'output_tokens', 'cost_micro_usd', 'reached_model', 'flagged', 'flag_reason', 'reported_at', 'confirmed_at',
        'purge_after',
    ];

    protected $hidden = ['prompt_text'];

    protected function casts(): array
    {
        return [
            'request_text' => 'encrypted',
            'prompt_text' => 'encrypted',
            'output_text' => 'encrypted',
            'output_data' => 'encrypted:array',
            'context' => 'array',
            'age_known' => 'boolean',
            'reached_model' => 'boolean',
            'flagged' => 'boolean',
            'input_tokens' => 'integer',
            'output_tokens' => 'integer',
            'cost_micro_usd' => 'integer',
            'reported_at' => 'datetime',
            'confirmed_at' => 'datetime',
            'purge_after' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return HasMany<AiQuestionDraft, $this> */
    public function drafts(): HasMany
    {
        return $this->hasMany(AiQuestionDraft::class, 'interaction_id');
    }

    /** @param  Builder<self>  $query */
    public function scopeDue(Builder $query): void
    {
        $query->whereNotNull('purge_after')->where('purge_after', '<', now());
    }

    public function answered(): bool
    {
        return in_array($this->status, ['ok', 'incomplete', 'awaiting_confirmation', 'confirmed'], true);
    }

    public function keepAsEvidence(): void
    {
        $this->forceFill(['purge_after' => now()->addDays((int) config('ai.evidence_retention_days', 730))])->save();
    }

    public function statusMessage(): ?string
    {
        return match ($this->status) {
            'ok', 'awaiting_confirmation', 'confirmed' => null,
            'incomplete' => 'The answer was cut off before it finished. What is shown is partial.',
            'refused_by_model' => Refusal::messageFor('refused_by_model'),
            default => Refusal::messageFor((string) $this->refusal_code),
        };
    }

    /** JSON for the person who asked (never the system prompt, tokens or cost). @return array<string, mixed> */
    public function present(): array
    {
        return [
            'id' => $this->id,
            'task' => $this->task,
            'status' => $this->status,
            'refusal_code' => $this->refusal_code,
            'message' => $this->statusMessage(),
            'label' => self::LABEL,
            'disclaimer' => self::DISCLAIMER,
            'language' => $this->language,
            'model' => $this->model,
            'mode' => $this->mode,
            'text' => $this->answered() ? $this->output_text : null,
            'data' => $this->answered() ? $this->output_data : null,
            'safety_note' => $this->context['safety_note'] ?? null,
            'language_note' => $this->context['language_note'] ?? null,
            'ask_a_tutor_url' => route('marketplace.find', array_filter(['subject_id' => $this->context['subject_id'] ?? null])),
            'report_url' => route('ai.interactions.report', $this),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }

    public function reportedUser(): ?User
    {
        return null;
    }

    /** @return list<int> */
    public function involvedUserIds(): array
    {
        return array_values(array_filter([$this->user_id]));
    }

    /** @return array<string, mixed> */
    public function reportSnapshot(): array
    {
        return [
            'type' => 'ai_interaction',
            'id' => $this->id,
            'task' => $this->task,
            'age_band' => $this->age_band,
            'model' => $this->model,
            'prompt_version' => $this->prompt_version,
            'request' => Str::limit((string) $this->request_text, 2000),
            'output' => Str::limit((string) $this->output_text, 4000),
            'at' => $this->created_at?->toIso8601String(),
        ];
    }
}
