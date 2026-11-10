<?php

namespace App\Domain\Academic\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Institution extends Model
{
    protected $fillable = ['country_id', 'name', 'short_name', 'kind', 'is_public', 'is_active'];

    protected $casts = ['is_public' => 'boolean', 'is_active' => 'boolean'];

    /** @return BelongsTo<Country, $this> */
    public function country(): BelongsTo
    {
        return $this->belongsTo(Country::class);
    }

    /** @param  Builder<self>  $query */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }
}
