<?php

namespace App\Domain\Community\Models;

use Illuminate\Database\Eloquent\Model;

/** The organiser's record that a member attended a session (the "study sessions attended" indicator). */
class SessionAttendance extends Model
{
    protected $table = 'community_session_attendance';

    protected $fillable = ['session_id', 'user_id', 'marked_by_id'];
}
