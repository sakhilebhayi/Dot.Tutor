<?php

namespace App\Domain\Safety\Models;

use App\Domain\Safety\Enums\IntegrityKind;
use Illuminate\Database\Eloquent\Model;

class IntegrityCase extends Model
{
    public const OPEN = 'open';

    public const UPHELD = 'upheld';

    public const NOT_UPHELD = 'not_upheld';

    public const SANCTIONS = ['none', 'warning', 'content_removed', 'review_removed', 'label_removed', 'suspension'];

    protected $table = 'safety_integrity_cases';

    protected $fillable = [
        'kind', 'subject_type', 'subject_id', 'subject_user_id', 'report_id', 'opened_by_id', 'status', 'summary',
        'finding', 'sanction', 'decided_by_id', 'decided_at',
    ];

    protected function casts(): array
    {
        return ['kind' => IntegrityKind::class, 'decided_at' => 'datetime'];
    }
}
