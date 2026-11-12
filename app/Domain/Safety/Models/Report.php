<?php

namespace App\Domain\Safety\Models;

use App\Domain\Safety\Enums\ReportCategory;
use App\Domain\Safety\Enums\ReportPriority;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class Report extends Model
{
    public const OPEN = 'open';

    public const ACTIONED = 'actioned';

    public const DISMISSED = 'dismissed';

    public const ESCALATED = 'escalated';

    protected $table = 'safety_reports';

    protected $fillable = [
        'reporter_id', 'reportable_type', 'reportable_id', 'reported_user_id', 'category', 'priority', 'status',
        'details', 'involves_minor', 'snapshot', 'respond_by', 'assigned_to_id', 'incident_id', 'resolved_at',
        'resolution',
    ];

    protected function casts(): array
    {
        return [
            'category' => ReportCategory::class,
            'priority' => ReportPriority::class,
            'involves_minor' => 'boolean',
            'snapshot' => 'array',
            'respond_by' => 'datetime',
            'resolved_at' => 'datetime',
        ];
    }

    /** @return MorphTo<Model, $this> */
    public function reportable(): MorphTo
    {
        return $this->morphTo();
    }

    /** @return BelongsTo<User, $this> */
    public function reporter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reporter_id');
    }

    /** @return BelongsTo<User, $this> */
    public function reportedUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reported_user_id');
    }

    /** @return BelongsTo<Incident, $this> */
    public function incident(): BelongsTo
    {
        return $this->belongsTo(Incident::class);
    }

    public function isOpen(): bool
    {
        return in_array($this->status, [self::OPEN, self::ESCALATED], true);
    }
}
