<?php

namespace App\Domain\Ai\Support;

use App\Domain\Learning\Models\LibraryResource;

/**
 * A library resource as the academic Translations store sees it. Library resources record their language in
 * `language`, not `original_locale`, so this read-only view supplies `original_locale` from it (D-912). It is
 * registered under the alias learning.resource only when the learning domain has not registered one itself.
 */
class TranslatableResource extends LibraryResource
{
    public function getOriginalLocaleAttribute(): string
    {
        return (string) ($this->attributes['language'] ?? '') ?: 'en';
    }
}
