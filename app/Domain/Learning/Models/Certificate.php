<?php

namespace App\Domain\Learning\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Certificate extends Model
{
    protected $table = 'learning_certificates';

    protected $fillable = [
        'enrolment_id', 'course_id', 'user_id', 'code', 'learner_name', 'course_title', 'tutor_name',
        'course_version_number', 'show_full_name', 'issued_at', 'revoked_at',
    ];

    protected function casts(): array
    {
        return ['issued_at' => 'datetime', 'revoked_at' => 'datetime', 'show_full_name' => 'boolean'];
    }

    /** @return BelongsTo<Enrolment, $this> */
    public function enrolment(): BelongsTo
    {
        return $this->belongsTo(Enrolment::class, 'enrolment_id');
    }

    /** The name shown on public verification: initials unless the holder chose to show their full name. */
    public function publicName(): string
    {
        if ($this->show_full_name) {
            return $this->learner_name;
        }
        $parts = preg_split('/\s+/', trim($this->learner_name)) ?: [];
        $first = array_shift($parts) ?? '';
        $initials = implode(' ', array_map(fn (string $part) => mb_substr($part, 0, 1).'.', $parts));

        return trim(mb_substr($first, 0, 1).'. '.$initials);
    }
}
