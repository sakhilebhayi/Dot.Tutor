<?php

namespace App\Domain\Learning\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A recorded review by a named reviewer. The only thing that can earn a resource a reviewed or official label. */
class ResourceReview extends Model
{
    protected $table = 'learning_resource_reviews';

    protected $fillable = ['resource_id', 'reviewer_id', 'reviewer_name', 'outcome', 'notes'];

    /** @return BelongsTo<LibraryResource, $this> */
    public function resource(): BelongsTo
    {
        return $this->belongsTo(LibraryResource::class, 'resource_id');
    }
}
