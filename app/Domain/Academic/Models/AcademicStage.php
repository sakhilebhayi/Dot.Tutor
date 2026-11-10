<?php

namespace App\Domain\Academic\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Seeded grouping of levels: foundation, intermediate, senior, fet, undergraduate, honours, ... */
class AcademicStage extends Model
{
    protected $fillable = ['code', 'name', 'kind', 'sort'];

    protected $casts = ['sort' => 'integer'];

    /** @return HasMany<AcademicLevel, $this> */
    public function levels(): HasMany
    {
        return $this->hasMany(AcademicLevel::class);
    }

    public function isTertiary(): bool
    {
        return $this->kind === 'tertiary';
    }
}
