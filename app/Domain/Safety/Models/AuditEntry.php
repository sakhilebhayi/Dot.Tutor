<?php

namespace App\Domain\Safety\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * One row of the append-only audit log. Written only by DatabaseAuditLog; the model refuses updates and deletes,
 * and the hash chain shows any change made around the model (safety:audit-verify).
 */
class AuditEntry extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'safety_audit_log';

    protected $fillable = [
        'actor_id', 'actor_label', 'action', 'subject_type', 'subject_id', 'context', 'prev_hash', 'hash', 'created_at',
    ];

    protected function casts(): array
    {
        return ['context' => 'array', 'created_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('The audit log is append-only.'));
        static::deleting(fn () => throw new LogicException('The audit log is append-only.'));
    }
}
