<?php

namespace App\Domain\Safety\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

/** The incident timeline. Append-only: an event is never edited or removed. */
class IncidentEvent extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'safety_incident_events';

    protected $fillable = ['incident_id', 'actor_id', 'type', 'body', 'created_at'];

    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Incident events are append-only.'));
        static::deleting(fn () => throw new LogicException('Incident events are append-only.'));
    }
}
