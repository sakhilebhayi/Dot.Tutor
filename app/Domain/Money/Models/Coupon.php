<?php

namespace App\Domain\Money\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A discount code. Tutor-funded codes reduce the tutor's selling price (and so the commission); platform
 * funded codes are a promotions expense and leave the tutor's earnings untouched.
 */
class Coupon extends Model
{
    public const FUNDED_BY_TUTOR = 'tutor';

    public const FUNDED_BY_PLATFORM = 'platform';

    protected $table = 'money_coupons';

    protected $guarded = ['id'];

    protected $casts = [
        'percent_bps' => 'integer',
        'amount_minor' => 'integer',
        'min_order_minor' => 'integer',
        'max_redemptions' => 'integer',
        'per_user_limit' => 'integer',
        'is_active' => 'boolean',
        'starts_at' => 'datetime',
        'expires_at' => 'datetime',
    ];

    public static function normalise(string $code): string
    {
        return strtoupper(preg_replace('/[^A-Za-z0-9_-]/', '', $code));
    }

    /** @return HasMany<CouponRedemption, $this> */
    public function redemptions(): HasMany
    {
        return $this->hasMany(CouponRedemption::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function toApi(): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'funded_by' => $this->funded_by,
            'kind' => $this->kind,
            'percent' => $this->percent_bps === null ? null : $this->percent_bps / 100,
            'amount_minor' => $this->amount_minor,
            'currency' => $this->currency,
            'applies_to_type' => $this->applies_to_type,
            'min_order_minor' => $this->min_order_minor,
            'max_redemptions' => $this->max_redemptions,
            'per_user_limit' => $this->per_user_limit,
            'starts_at' => $this->starts_at?->toIso8601String(),
            'expires_at' => $this->expires_at?->toIso8601String(),
            'is_active' => $this->is_active,
            'used' => $this->redemptions()->whereIn('status', ['reserved', 'confirmed'])->count(),
        ];
    }
}
