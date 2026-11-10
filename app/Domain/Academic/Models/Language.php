<?php

namespace App\Domain\Academic\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/** A language as data: BCP 47 code, English and own-language names, script, modality. */
class Language extends Model
{
    protected $fillable = ['code', 'name_en', 'name_native', 'script', 'modality', 'is_official_za', 'is_active', 'aliases', 'sort'];

    protected $casts = ['is_official_za' => 'boolean', 'is_active' => 'boolean', 'aliases' => 'array', 'sort' => 'integer'];

    public function isSigned(): bool
    {
        return $this->modality === 'signed';
    }

    /** "isiZulu" or "Portuguese (Português)". */
    public function displayName(): string
    {
        return $this->name_en === $this->name_native ? $this->name_en : "{$this->name_en} ({$this->name_native})";
    }

    /** @param  Builder<self>  $query */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }
}
