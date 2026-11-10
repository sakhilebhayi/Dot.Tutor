<?php

namespace App\Domain\Safety\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ConversationParticipant extends Model
{
    public const MEMBER = 'member';

    // A linked guardian of a minor member: reads everything, may write, may report.
    public const GUARDIAN = 'guardian';

    public const OPERATOR = 'operator';

    protected $table = 'safety_conversation_participants';

    protected $fillable = ['conversation_id', 'user_id', 'role', 'guardian_of_id', 'last_read_at', 'left_at'];

    protected function casts(): array
    {
        return ['last_read_at' => 'datetime', 'left_at' => 'datetime'];
    }

    /** @return BelongsTo<Conversation, $this> */
    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
