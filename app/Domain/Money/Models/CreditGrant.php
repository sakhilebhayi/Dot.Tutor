<?php

namespace App\Domain\Money\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A platform-funded promotional credit (or its revocation, negative). The balance itself lives in the
 * ledger (learner_credit:{user}); this row is the who, why and when.
 */
class CreditGrant extends Model
{
    protected $table = 'money_credit_grants';

    protected $guarded = ['id'];

    protected $casts = ['amount_minor' => 'integer'];
}
