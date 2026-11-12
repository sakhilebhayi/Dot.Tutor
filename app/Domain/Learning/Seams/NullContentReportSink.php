<?php

namespace App\Domain\Learning\Seams;

use App\Domain\Learning\Contracts\Seams\ContentReportSink;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/** Default until the safety domain merges: the report stays in the learning queue only (no shared queue exists). */
class NullContentReportSink implements ContentReportSink
{
    public function forward(User $reporter, Model $subject, string $kind, string $details): ?string
    {
        return null;
    }
}
