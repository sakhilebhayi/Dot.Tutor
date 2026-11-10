<?php

namespace App\Domain\Safety\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AgeRecord extends Model
{
    protected $table = 'safety_age_records';

    protected $fillable = ['user_id', 'birth_date', 'source', 'recorded_by_id', 'note'];

    protected function casts(): array
    {
        return ['birth_date' => 'immutable_date'];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
