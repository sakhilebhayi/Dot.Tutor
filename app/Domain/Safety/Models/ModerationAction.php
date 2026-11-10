<?php

namespace App\Domain\Safety\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

/** What a moderator did and why. Append-only. */
class ModerationAction extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'safety_moderation_actions';

    protected $fillable = [
        'actor_id', 'report_id', 'incident_id', 'action', 'target_type', 'target_id', 'target_user_id', 'reason',
        'meta', 'created_at',
    ];

    protected function casts(): array
    {
        return ['meta' => 'array', 'created_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Moderation actions are append-only.'));
        static::deleting(fn () => throw new LogicException('Moderation actions are append-only.'));
    }
}
