<?php

namespace App\Domain\Academic\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A pre-R-47 `subjects.level` string mapped onto one academic level. */
class LegacyLevelMapping extends Model
{
    protected $table = 'legacy_subject_level_map';

    protected $fillable = ['legacy_level', 'academic_level_id'];

    /** @return BelongsTo<AcademicLevel, $this> */
    public function level(): BelongsTo
    {
        return $this->belongsTo(AcademicLevel::class, 'academic_level_id');
    }
}
