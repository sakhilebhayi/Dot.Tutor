<?php

namespace App\Domain\Community\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** One line of a group's shared revision list; each member ticks it when they have revised it. */
class RevisionItem extends Model
{
    protected $table = 'community_revision_items';

    protected $fillable = ['group_id', 'added_by_id', 'title', 'topic_id'];

    /** @return HasMany<RevisionTick, $this> */
    public function ticks(): HasMany
    {
        return $this->hasMany(RevisionTick::class, 'item_id');
    }

    /** @return BelongsTo<User, $this> */
    public function addedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'added_by_id');
    }
}
