<?php

namespace App\Domain\Learning\Contracts\Seams;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/** The learning side of Safety\Audit::record. */
interface AuditTrail
{
    /** @param  array<string, mixed>  $context */
    public function record(?User $actor, string $action, ?Model $subject, array $context = []): void;
}
