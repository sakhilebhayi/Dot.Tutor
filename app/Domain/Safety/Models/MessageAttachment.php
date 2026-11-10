<?php

namespace App\Domain\Safety\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class MessageAttachment extends Model
{
    protected $table = 'safety_message_attachments';

    protected $fillable = ['message_id', 'path', 'original_name', 'mime', 'size_bytes', 'sha256'];

    protected $hidden = ['path'];

    protected static function booted(): void
    {
        static::deleted(function (MessageAttachment $attachment) {
            Storage::disk(config('safety.messaging.disk'))->delete($attachment->path);
        });
    }

    /** @return BelongsTo<Message, $this> */
    public function message(): BelongsTo
    {
        return $this->belongsTo(Message::class);
    }
}
