<?php

namespace App\Domain\Money\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A stored, signature-checked provider webhook (customer, card and metadata stripped).
 */
class WebhookEvent extends Model
{
    protected $table = 'money_webhook_events';

    protected $guarded = ['id'];

    protected $casts = [
        'payload' => 'array',
        'attempts' => 'integer',
        'processed_at' => 'datetime',
    ];
}
