<?php

namespace App\Domain\Safety\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Incident extends Model
{
    public const OPEN = 'open';

    public const ACKNOWLEDGED = 'acknowledged';

    // Referred to an outside body (for example SAPS or the Department of Social Development), recorded by hand.
    public const REFERRED = 'referred';

    public const CLOSED = 'closed';

    protected $table = 'safety_incidents';

    protected $fillable = [
        'reference', 'title', 'severity', 'status', 'opened_by_id', 'owner_id', 'learner_id', 'escalation_level',
        'respond_by', 'acknowledged_at', 'closed_at', 'outcome', 'external_reference',
    ];

    protected function casts(): array
    {
        return [
            'respond_by' => 'datetime',
            'acknowledged_at' => 'datetime',
            'closed_at' => 'datetime',
            'escalation_level' => 'integer',
        ];
    }

    /** @return HasMany<IncidentEvent, $this> */
    public function events(): HasMany
    {
        return $this->hasMany(IncidentEvent::class)->orderBy('id');
    }

    /** @return HasMany<Report, $this> */
    public function reports(): HasMany
    {
        return $this->hasMany(Report::class);
    }

    /** @return BelongsTo<User, $this> */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function isOpen(): bool
    {
        return $this->status !== self::CLOSED;
    }
}
