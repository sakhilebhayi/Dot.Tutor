<?php

namespace App\Domain\Academic\Models;

use App\Models\Subject as LegacySubject;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * The global subject catalogue, on the existing `subjects` table (same ids as
 * App\Models\Subject, which the booking code keeps using). The legacy `level`
 * string stays on every row; `canonical_subject_id` links a legacy duplicate
 * (e.g. "Mathematics, primary" next to "Mathematics, high school") to the
 * catalogue row that represents the subject. Rows are deactivated, never deleted.
 */
class Subject extends LegacySubject
{
    protected $table = 'subjects';

    protected $fillable = ['name', 'level', 'is_active', 'slug', 'family', 'category', 'aliases', 'canonical_subject_id', 'language_id', 'language_tier', 'description'];

    protected $casts = ['is_active' => 'boolean', 'aliases' => 'array'];

    /** @return BelongsTo<self, $this> */
    public function canonical(): BelongsTo
    {
        return $this->belongsTo(self::class, 'canonical_subject_id');
    }

    /** @return BelongsTo<Language, $this> */
    public function language(): BelongsTo
    {
        return $this->belongsTo(Language::class);
    }

    /** @return HasMany<SubjectOffering, $this> */
    public function offerings(): HasMany
    {
        return $this->hasMany(SubjectOffering::class, 'subject_id');
    }

    public function canonicalId(): int
    {
        return $this->canonical_subject_id ?? $this->id;
    }

    /**
     * Catalogue rows only (not legacy duplicates pointing at a canonical row).
     *
     * @param  Builder<self>  $query
     */
    public function scopeCanonical(Builder $query): void
    {
        $query->whereNull('canonical_subject_id');
    }
}
