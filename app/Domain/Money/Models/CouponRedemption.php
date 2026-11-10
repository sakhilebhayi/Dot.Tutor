<?php

namespace App\Domain\Money\Models;

use Illuminate\Database\Eloquent\Model;

class CouponRedemption extends Model
{
    protected $table = 'money_coupon_redemptions';

    protected $guarded = ['id'];

    protected $casts = ['discount_minor' => 'integer'];
}
