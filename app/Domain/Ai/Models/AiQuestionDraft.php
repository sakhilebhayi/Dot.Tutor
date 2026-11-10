<?php

namespace App\Domain\Ai\Models;

use App\Domain\Learning\Models\Question;
use App\Domain\Learning\Models\QuestionBank;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An AI-drafted question waiting for its tutor. It is never a learning Question until the tutor accepts it; accepting
 * creates the question in the tutor's own bank through the same key validation learning uses (D-910).
 */
class AiQuestionDraft extends Model
{
    protected $table = 'ai_question_drafts';

    protected $fillable = [
        'interaction_id', 'owner_id', 'bank_id', 'type', 'prompt', 'options', 'answer', 'explanation', 'topic',
        'difficulty', 'marks', 'status', 'question_id', 'decided_at',
    ];

    protected function casts(): array
    {
        return [
            'options' => 'array',
            'answer' => 'array',
            'marks' => 'integer',
            'decided_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<QuestionBank, $this> */
    public function bank(): BelongsTo
    {
        return $this->belongsTo(QuestionBank::class, 'bank_id');
    }

    /** @return BelongsTo<User, $this> */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    /** @return BelongsTo<Question, $this> */
    public function question(): BelongsTo
    {
        return $this->belongsTo(Question::class, 'question_id');
    }

    /** @return array<string, mixed> */
    public function present(): array
    {
        return [
            'id' => $this->id,
            'bank_id' => $this->bank_id,
            'type' => $this->type,
            'prompt' => $this->prompt,
            'options' => $this->options,
            'answer' => $this->answer,
            'explanation' => $this->explanation,
            'topic' => $this->topic,
            'difficulty' => $this->difficulty,
            'marks' => $this->marks,
            'status' => $this->status,
            'question_id' => $this->question_id,
            'label' => 'AI draft: check the question and the answer key before accepting',
        ];
    }
}
