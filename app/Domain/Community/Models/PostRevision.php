<?php

namespace App\Domain\Community\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** The text a post had before an edit (append-only: edit history is kept). */
class PostRevision extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'community_post_revisions';

    protected $fillable = ['post_id', 'editor_id', 'title', 'body'];

    protected static function booted(): void
    {
        static::updating(fn () => false);
        static::deleting(fn () => false);
    }

    /** @return BelongsTo<User, $this> */
    public function editor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'editor_id');
    }
}
