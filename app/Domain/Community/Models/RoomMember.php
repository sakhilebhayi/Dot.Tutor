<?php

namespace App\Domain\Community\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RoomMember extends Model
{
    public const ACTIVE = 'active';

    public const PENDING = 'pending';

    public const AWAITING_GUARDIAN = 'awaiting_guardian';

    public const AWAITING_PAYMENT = 'awaiting_payment';

    public const LEFT = 'left';

    public const REMOVED = 'removed';

    public const LEARNER = 'learner';

    public const TUTOR = 'tutor';

    public const OWNER = 'owner';

    protected $table = 'community_room_members';

    protected $fillable = ['room_id', 'user_id', 'role', 'status', 'holds_seat', 'joined_at'];

    protected function casts(): array
    {
        return ['joined_at' => 'datetime', 'holds_seat' => 'boolean'];
    }

    /** @return BelongsTo<Room, $this> */
    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isActive(): bool
    {
        return $this->status === self::ACTIVE;
    }

    public function statusLabel(): string
    {
        return match ($this->status) {
            self::ACTIVE => 'Member',
            self::PENDING => 'Waiting for the tutor to accept',
            self::AWAITING_GUARDIAN => 'Waiting for a parent or guardian to approve',
            self::AWAITING_PAYMENT => 'Waiting for payment',
            self::LEFT => 'Left',
            self::REMOVED => 'Removed',
            default => ucfirst(str_replace('_', ' ', $this->status)),
        };
    }
}
