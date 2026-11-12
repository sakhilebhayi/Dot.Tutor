<?php

namespace App\Domain\Academic\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A curriculum belongs to a qualification framework and, unless it is
 * international (country_id null, e.g. Cambridge), to one country.
 */
class Curriculum extends Model
{
    protected $table = 'curricula';

    protected $fillable = ['country_id', 'qualification_framework_id', 'code', 'name', 'short_name', 'kind', 'authority', 'description', 'is_active', 'sort'];

    protected $casts = ['is_active' => 'boolean', 'sort' => 'integer'];

    /** @return BelongsTo<Country, $this> */
    public function country(): BelongsTo
    {
        return $this->belongsTo(Country::class);
    }

    /** @return BelongsTo<QualificationFramework, $this> */
    public function framework(): BelongsTo
    {
        return $this->belongsTo(QualificationFramework::class, 'qualification_framework_id');
    }

    /** @return HasMany<AcademicLevel, $this> */
    public function levels(): HasMany
    {
        return $this->hasMany(AcademicLevel::class)->orderBy('position');
    }

    /** @return HasMany<SubjectOffering, $this> */
    public function offerings(): HasMany
    {
        return $this->hasMany(SubjectOffering::class);
    }

    public function isInternational(): bool
    {
        return $this->country_id === null;
    }

    public function label(): string
    {
        return $this->short_name ?: $this->name;
    }

    /** @param  Builder<self>  $query */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    /**
     * Curricula a learner in this country can follow: the country's own plus
     * international ones.
     *
     * @param  Builder<self>  $query
     */
    public function scopeAvailableIn(Builder $query, ?int $countryId): void
    {
        $query->where(fn (Builder $q) => $q->whereNull('country_id')->when($countryId, fn (Builder $q) => $q->orWhere('country_id', $countryId)));
    }
}
