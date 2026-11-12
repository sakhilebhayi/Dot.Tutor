<?php

namespace App\Domain\Community\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A lightweight group assignment: a task with a due date and who did what. */
class GroupTask extends Model
{
    protected $table = 'community_tasks';

    protected $fillable = ['group_id', 'created_by_id', 'title', 'details', 'due_on'];

    protected function casts(): array
    {
        return ['due_on' => 'date'];
    }

    /** @return HasMany<TaskAssignee, $this> */
    public function assignees(): HasMany
    {
        return $this->hasMany(TaskAssignee::class, 'task_id');
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }
}
