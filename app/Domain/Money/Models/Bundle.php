<?php

namespace App\Domain\Money\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A tutor's bundle of their own items sold at one price (for example a course plus revision notes).
 */
class Bundle extends Model
{
    protected $table = 'money_bundles';

    protected $guarded = ['id'];

    protected $casts = [
        'price_minor' => 'integer',
        'is_active' => 'boolean',
    ];

    /** @return HasMany<BundleItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(BundleItem::class);
    }
}
