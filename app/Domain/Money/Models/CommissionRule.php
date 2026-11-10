<?php

namespace App\Domain\Money\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * A versioned commission percentage, the platform default (category null) or a category override. Never
 * edited: a new version supersedes the old one, so every order line points at the rule it was priced with.
 */
class CommissionRule extends Model
{
    protected $table = 'money_commission_rules';

    protected $guarded = ['id'];

    protected $casts = [
        'percent_bps' => 'integer',
        'version' => 'integer',
        'effective_from' => 'datetime',
        'superseded_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::updating(function (CommissionRule $rule) {
            if (array_diff(array_keys($rule->getDirty()), ['superseded_at', 'updated_at']) !== []) {
                throw new LogicException('Commission rules are versioned: add a new version instead of editing one.');
            }
        });
        static::deleting(fn () => throw new LogicException('Commission rules are never deleted.'));
    }
}
