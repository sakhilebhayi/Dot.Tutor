<?php

namespace App\Domain\Academic\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** One subject offered at one level of one curriculum (table curriculum_subject_levels). */
class SubjectOffering extends Model
{
    protected $table = 'curriculum_subject_levels';

    protected $fillable = ['curriculum_id', 'academic_level_id', 'subject_id', 'is_compulsory', 'is_active'];

    protected $casts = ['is_compulsory' => 'boolean', 'is_active' => 'boolean'];

    protected static function booted(): void
    {
        // The curriculum is always the level's curriculum; it is stored for fast filtering, never trusted from input.
        static::saving(function (self $offering): void {
            $offering->curriculum_id = AcademicLevel::query()->whereKey($offering->academic_level_id)->value('curriculum_id');
        });
    }

    /** @return BelongsTo<Curriculum, $this> */
    public function curriculum(): BelongsTo
    {
        return $this->belongsTo(Curriculum::class);
    }

    /** @return BelongsTo<AcademicLevel, $this> */
    public function level(): BelongsTo
    {
        return $this->belongsTo(AcademicLevel::class, 'academic_level_id');
    }

    /** @return BelongsTo<Subject, $this> */
    public function subject(): BelongsTo
    {
        return $this->belongsTo(Subject::class);
    }

    /** @return HasMany<Topic, $this> */
    public function topics(): HasMany
    {
        return $this->hasMany(Topic::class, 'curriculum_subject_level_id')->orderBy('position');
    }

    /** @param  Builder<self>  $query */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }
}
