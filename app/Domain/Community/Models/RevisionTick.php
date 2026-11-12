<?php

namespace App\Domain\Community\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RevisionTick extends Model
{
    protected $table = 'community_revision_ticks';

    protected $fillable = ['item_id', 'user_id'];

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
