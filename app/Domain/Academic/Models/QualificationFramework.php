<?php

namespace App\Domain\Academic\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class QualificationFramework extends Model
{
    protected $fillable = ['country_id', 'code', 'name', 'short_name', 'authority', 'description', 'is_active'];

    protected $casts = ['is_active' => 'boolean'];

    /** @return BelongsTo<Country, $this> */
    public function country(): BelongsTo
    {
        return $this->belongsTo(Country::class);
    }

    /** @return HasMany<Curriculum, $this> */
    public function curricula(): HasMany
    {
        return $this->hasMany(Curriculum::class);
    }
}
