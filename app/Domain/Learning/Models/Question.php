<?php

namespace App\Domain\Learning\Models;

use App\Domain\Learning\Enums\QuestionType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A question with its answer key. The key (`answer`) and explanation are hidden from serialisation: they only ever
 * reach a learner through the marking result, after submission, and only when the assessment reveals answers.
 */
class Question extends Model
{
    protected $table = 'learning_questions';

    protected $fillable = [
        'bank_id', 'type', 'prompt', 'options', 'answer', 'explanation', 'topic', 'marks', 'difficulty', 'retired_at',
    ];

    protected $hidden = ['answer', 'explanation'];

    protected function casts(): array
    {
        return [
            'type' => QuestionType::class,
            'options' => 'array',
            'answer' => 'array',
            'marks' => 'integer',
            'retired_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<QuestionBank, $this> */
    public function bank(): BelongsTo
    {
        return $this->belongsTo(QuestionBank::class, 'bank_id');
    }

    public function topicLabel(): string
    {
        return $this->topic ?: $this->bank->topic;
    }

    /** The question as a learner sees it while answering: no key, no explanation. */
    public function forLearner(): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type->value,
            'prompt' => $this->prompt,
            'options' => collect($this->options ?? [])->map(fn (array $o) => ['key' => $o['key'], 'text' => $o['text']])->values()->all(),
            'marks' => $this->marks,
        ];
    }
}
