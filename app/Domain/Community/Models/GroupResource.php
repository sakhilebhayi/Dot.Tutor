<?php

namespace App\Domain\Community\Models;

use App\Domain\Learning\Models\LibraryResource;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A library resource a member shared with the group (a link: learning owns the file and its licence). */
class GroupResource extends Model
{
    protected $table = 'community_group_resources';

    protected $fillable = ['group_id', 'library_resource_id', 'added_by_id', 'note'];

    /** @return BelongsTo<LibraryResource, $this> */
    public function resource(): BelongsTo
    {
        return $this->belongsTo(LibraryResource::class, 'library_resource_id');
    }

    /** @return BelongsTo<User, $this> */
    public function addedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'added_by_id');
    }
}
