<?php

namespace App\Domain\Marketplace\Models;

use App\Domain\Marketplace\Services\PrivateFiles;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A private evidence file. Readable only by the tutor who owns it and operators. */
class QualificationEvidence extends Model
{
    protected $table = 'tutor_qualification_evidence';

    protected $fillable = ['tutor_qualification_id', 'uploaded_by', 'path', 'original_name', 'mime', 'size_bytes', 'sha256'];

    protected $casts = ['size_bytes' => 'integer'];

    protected static function booted(): void
    {
        static::deleted(fn (QualificationEvidence $evidence) => app(PrivateFiles::class)->delete($evidence->path));
    }

    /** @return BelongsTo<TutorQualification, $this> */
    public function qualification(): BelongsTo
    {
        return $this->belongsTo(TutorQualification::class, 'tutor_qualification_id');
    }
}
