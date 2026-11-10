<?php

namespace App\Domain\Safety\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class AccountRestriction extends Model
{
    public const SUSPENSION = 'suspension';

    public const WARNING = 'warning';

    protected $table = 'safety_account_restrictions';

    protected $fillable = [
        'user_id', 'kind', 'reason', 'imposed_by_id', 'starts_at', 'ends_at', 'lifted_at', 'lifted_by_id',
    ];

    protected function casts(): array
    {
        return ['starts_at' => 'datetime', 'ends_at' => 'datetime', 'lifted_at' => 'datetime'];
    }

    /** @param Builder<AccountRestriction> $query */
    public function scopeInEffect(Builder $query): void
    {
        $query->whereNull('lifted_at')
            ->where('starts_at', '<=', now())
            ->where(fn (Builder $q) => $q->whereNull('ends_at')->orWhere('ends_at', '>', now()));
    }
}
