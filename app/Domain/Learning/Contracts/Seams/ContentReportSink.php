<?php

namespace App\Domain\Learning\Contracts\Seams;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * The learning side of Safety\Contracts\ReportSink. The learning domain always keeps its own record of a report
 * (learning_resource_reports); this forwards it to the shared moderation queue and returns that queue's reference.
 */
interface ContentReportSink
{
    public function forward(User $reporter, Model $subject, string $kind, string $details): ?string;
}
