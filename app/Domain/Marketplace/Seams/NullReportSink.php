<?php

namespace App\Domain\Marketplace\Seams;

use App\Domain\Marketplace\Contracts\ReportSinkPort;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/** No safety moderation queue connected; the marketplace keeps its own held-review queue. */
class NullReportSink implements ReportSinkPort
{
    public function report(Model $reportable, ?User $reporter, string $reason, array $context = []): ?string
    {
        return null;
    }
}
