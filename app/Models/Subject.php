<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Subject extends Model
{
    protected $fillable = ['name', 'level', 'is_active'];

    protected $casts = ['is_active' => 'boolean'];

    public const LEVELS = [
        'primary' => 'Primary school',
        'high_school' => 'High school',
        'university' => 'University',
        'adult' => 'Adult learning',
    ];

    /** @return BelongsToMany<TutorProfile, $this> */
    public function tutors(): BelongsToMany
    {
        return $this->belongsToMany(TutorProfile::class, 'tutor_subject');
    }

    /**
     * Subjects offered for NEW applications and bookings. A deactivated
     * subject stays on every session that already used it.
     *
     * @param  Builder<self>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }
}
