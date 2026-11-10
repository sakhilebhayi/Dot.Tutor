<?php

namespace App\Domain\Learning\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Assignment extends Model
{
    protected $table = 'learning_assignments';

    protected $fillable = ['assessment_id', 'learner_id', 'assigned_by', 'due_at'];

    protected function casts(): array
    {
        return ['due_at' => 'datetime'];
    }

    /** @return BelongsTo<Assessment, $this> */
    public function assessment(): BelongsTo
    {
        return $this->belongsTo(Assessment::class, 'assessment_id');
    }
}
