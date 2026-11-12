<?php

namespace App\Domain\Integration\Adapters\Marketplace;

use App\Domain\Marketplace\Contracts\ReportSinkPort;
use App\Domain\Safety\Contracts\ReportSink;
use App\Domain\Safety\Enums\ReportCategory;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Review reports filed by people go to the safety moderation queue (category "other", the person's words as the
 * details). Automatic holds (contact details found by the marketplace's own screen) have no reporter: they stay
 * in the marketplace's held-review queue only, because a safety report must name the person who filed it.
 */
class SafetyReportSinkPort implements ReportSinkPort
{
    public function __construct(private readonly ReportSink $sink) {}

    public function report(Model $reportable, ?User $reporter, string $reason, array $context = []): ?string
    {
        if ($reporter === null) {
            return null;
        }

        $report = $this->sink->report($reporter, $reportable, ReportCategory::Other, mb_substr($reason, 0, 2000));

        return 'safety-report:'.$report->id;
    }
}
