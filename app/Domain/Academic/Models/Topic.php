<?php

namespace App\Domain\Academic\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Topic extends Model
{
    protected $table = 'academic_topics';

    protected $fillable = ['curriculum_subject_level_id', 'parent_id', 'name', 'slug', 'position', 'description', 'is_active'];

    protected $casts = ['is_active' => 'boolean', 'position' => 'integer'];

    /** @return BelongsTo<SubjectOffering, $this> */
    public function offering(): BelongsTo
    {
        return $this->belongsTo(SubjectOffering::class, 'curriculum_subject_level_id');
    }

    /** @return BelongsTo<self, $this> */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /** @return HasMany<self, $this> */
    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->orderBy('position');
    }
}
