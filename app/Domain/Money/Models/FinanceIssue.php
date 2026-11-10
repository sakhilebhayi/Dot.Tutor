<?php

namespace App\Domain\Money\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * The finance exceptions list: unmatched payments, paid-after-close orders, failed refunds, reconciliation
 * mismatches. Operations resolve each one with a note.
 */
class FinanceIssue extends Model
{
    protected $table = 'money_finance_issues';

    protected $guarded = ['id'];

    protected $casts = [
        'details' => 'array',
        'amount_minor' => 'integer',
        'resolved_at' => 'datetime',
    ];
}
