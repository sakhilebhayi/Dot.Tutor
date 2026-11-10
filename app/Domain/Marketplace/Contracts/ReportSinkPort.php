<?php

namespace App\Domain\Marketplace\Contracts;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/** Owned by safety as Safety\Contracts\ReportSink (the moderation queue). */
interface ReportSinkPort
{
    /**
     * @param  array<string, mixed>  $context
     * @return string|null a report reference, or null when no moderation queue is connected
     */
    public function report(Model $reportable, ?User $reporter, string $reason, array $context = []): ?string;
}
