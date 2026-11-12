<?php

namespace App\Domain\Teach\Models;

use Illuminate\Database\Eloquent\Model;

class ReferralVisit extends Model
{
    public $timestamps = false;

    protected $table = 'teach_referral_visits';

    protected $fillable = ['teach_referral_link_id', 'visitor_id', 'visited_at'];

    protected $casts = ['visited_at' => 'datetime'];
}
