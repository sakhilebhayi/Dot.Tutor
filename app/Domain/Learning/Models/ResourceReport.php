<?php

namespace App\Domain\Learning\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ResourceReport extends Model
{
    protected $table = 'learning_resource_reports';

    protected $fillable = [
        'resource_id', 'reporter_id', 'kind', 'details', 'claimant_name', 'work_reference', 'status',
        'counter_notice', 'decided_by', 'decision_notes', 'decided_at', 'forwarded_reference',
    ];

    protected function casts(): array
    {
        return ['decided_at' => 'datetime'];
    }

    /** @return BelongsTo<LibraryResource, $this> */
    public function resource(): BelongsTo
    {
        return $this->belongsTo(LibraryResource::class, 'resource_id');
    }
}
