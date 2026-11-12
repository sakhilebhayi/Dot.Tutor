<?php

namespace App\Domain\Classroom\Models;

use Illuminate\Database\Eloquent\Model;

/** A short-lived WebRTC set-up message between two browsers (offer, answer, ICE candidates). Purged within minutes. */
class ClassroomSignal extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['classroom_id', 'from_attendee_id', 'to_attendee_id', 'type', 'payload', 'created_at'];
}
