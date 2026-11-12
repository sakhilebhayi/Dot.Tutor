<?php

namespace App\Domain\Safety\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * A competent person's consent (POPIA s35(1)(a)) recorded with the text version and a hash of the exact
 * text shown. Withdrawal stamps the row; rows are never deleted.
 */
class Consent extends Model
{
    public const PROCESSING = 'processing';

    public const LESSON_RECORDING = 'lesson_recording';

    protected $table = 'safety_consents';

    protected $fillable = [
        'learner_id', 'guardian_id', 'guardian_link_id', 'kind', 'version', 'text_hash', 'granted_at', 'withdrawn_at',
    ];

    protected function casts(): array
    {
        return ['granted_at' => 'datetime', 'withdrawn_at' => 'datetime'];
    }

    /** @param Builder<Consent> $query */
    public function scopeInForce(Builder $query): void
    {
        $query->whereNull('withdrawn_at');
    }
}
