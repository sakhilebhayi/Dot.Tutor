<?php

namespace App\Domain\Academic\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** An ordered level inside one curriculum (Grade 11 in CAPS, Honours in SA higher education, IGCSE in Cambridge). */
class AcademicLevel extends Model
{
    protected $fillable = ['curriculum_id', 'academic_stage_id', 'code', 'name', 'short_name', 'position', 'nqf_level', 'aliases', 'is_active'];

    protected $casts = ['aliases' => 'array', 'is_active' => 'boolean', 'position' => 'integer', 'nqf_level' => 'integer'];

    /** @return BelongsTo<Curriculum, $this> */
    public function curriculum(): BelongsTo
    {
        return $this->belongsTo(Curriculum::class);
    }

    /** @return BelongsTo<AcademicStage, $this> */
    public function stage(): BelongsTo
    {
        return $this->belongsTo(AcademicStage::class, 'academic_stage_id');
    }

    /** @return HasMany<SubjectOffering, $this> */
    public function offerings(): HasMany
    {
        return $this->hasMany(SubjectOffering::class);
    }

    public function isTertiary(): bool
    {
        return (bool) $this->stage?->isTertiary();
    }

    /** @param  Builder<self>  $query */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }
}
