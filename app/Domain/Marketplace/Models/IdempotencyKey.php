<?php

namespace App\Domain\Marketplace\Models;

use Illuminate\Database\Eloquent\Model;

class IdempotencyKey extends Model
{
    protected $table = 'marketplace_idempotency_keys';

    protected $fillable = ['user_id', 'scope', 'idempotency_key', 'request_hash', 'resource_type', 'resource_id'];

    protected $casts = ['resource_id' => 'integer'];
}
