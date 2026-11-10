<?php

namespace App\Domain\Marketplace\Models;

use App\Models\SessionRating;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReviewReport extends Model
{
    protected $table = 'tutor_review_reports';

    protected $fillable = ['session_rating_id', 'reporter_id', 'reason', 'sink_reference'];

    /** @return BelongsTo<SessionRating, $this> */
    public function review(): BelongsTo
    {
        return $this->belongsTo(SessionRating::class, 'session_rating_id');
    }
}
