<?php

namespace App\Domain\Community\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GroupMember extends Model
{
    public const ACTIVE = 'active';

    public const PENDING = 'pending';

    public const LEFT = 'left';

    public const REMOVED = 'removed';

    protected $table = 'community_group_members';

    protected $fillable = ['group_id', 'user_id', 'role', 'status', 'joined_at'];

    protected function casts(): array
    {
        return ['joined_at' => 'datetime'];
    }

    /** @return BelongsTo<StudyGroup, $this> */
    public function group(): BelongsTo
    {
        return $this->belongsTo(StudyGroup::class, 'group_id');
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
