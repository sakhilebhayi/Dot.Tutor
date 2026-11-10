<?php

namespace App\Domain\Comms\Models;

use Illuminate\Database\Eloquent\Model;

/** A person's quiet hours (local wall-clock times) and the time zone they are read in. */
class UserCommsSettings extends Model
{
    protected $table = 'comms_user_settings';

    protected $fillable = ['user_id', 'quiet_enabled', 'quiet_start', 'quiet_end', 'timezone'];

    protected $casts = ['quiet_enabled' => 'boolean'];
}
