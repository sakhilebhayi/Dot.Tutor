<?php

namespace App\Domain\Money\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A person's access to an item: paid, free, gifted or through a bundle. Revoked (never deleted) on a full refund.
 */
class Entitlement extends Model
{
    public const SOURCES = ['paid', 'free', 'gifted', 'bundle'];

    protected $table = 'money_entitlements';

    protected $guarded = ['id'];

    protected $casts = ['revoked_at' => 'datetime'];
}
