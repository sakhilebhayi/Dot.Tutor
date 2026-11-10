<?php

namespace App\Domain\Money\Ledger\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

/**
 * One posted business event. Append-only: written once by App\Domain\Money\Ledger\Ledger::post.
 */
class LedgerJournal extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'money_ledger_journals';

    protected $guarded = ['id'];

    protected $casts = ['posted_at' => 'datetime'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Ledger journals are immutable.'));
        static::deleting(fn () => throw new LogicException('Ledger journals are immutable.'));
    }

    /** @return HasMany<LedgerEntry, $this> */
    public function entries(): HasMany
    {
        return $this->hasMany(LedgerEntry::class, 'journal_id');
    }
}
