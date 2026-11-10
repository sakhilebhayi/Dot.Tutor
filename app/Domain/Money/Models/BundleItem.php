<?php

namespace App\Domain\Money\Models;

use Illuminate\Database\Eloquent\Model;

class BundleItem extends Model
{
    protected $table = 'money_bundle_items';

    protected $guarded = ['id'];
}
