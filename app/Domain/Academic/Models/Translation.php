<?php

namespace App\Domain\Academic\Models;

use App\Domain\Academic\Enums\ReviewStatus;
use App\Domain\Academic\Enums\TranslationProvenance;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One translated field of one piece of content. The original stays in the
 * owning model's own column; `source_hash` is the hash of that original at
 * translation time, so an edited original marks its translations out of date.
 * Written only through App\Domain\Academic\Translations.
 */
class Translation extends Model
{
    protected $fillable = [
        'translatable_type', 'translatable_id', 'field', 'locale', 'body', 'provenance',
        'translator_id', 'translator_label', 'machine_model', 'source_hash',
    ];

    protected $casts = [
        'provenance' => TranslationProvenance::class,
        'review_status' => ReviewStatus::class,
        'reviewed_at' => 'datetime',
    ];

    protected $attributes = ['review_status' => 'unreviewed'];

    /** @return BelongsTo<User, $this> */
    public function translator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'translator_id');
    }

    /** @return BelongsTo<User, $this> */
    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by_id');
    }
}
