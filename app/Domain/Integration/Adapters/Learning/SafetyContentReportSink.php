<?php

namespace App\Domain\Integration\Adapters\Learning;

use App\Domain\Learning\Contracts\Seams\ContentReportSink;
use App\Domain\Safety\Contracts\ReportSink;
use App\Domain\Safety\Enums\ReportCategory;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/** Library and course reports are forwarded to the safety moderation queue; learning keeps its own record too. */
class SafetyContentReportSink implements ContentReportSink
{
    public function __construct(private readonly ReportSink $sink) {}

    public function forward(User $reporter, Model $subject, string $kind, string $details): ?string
    {
        $category = match ($kind) {
            'copyright' => ReportCategory::Copyright,
            'incorrect' => ReportCategory::Misinformation,
            'spam' => ReportCategory::Spam,
            'cheating' => ReportCategory::CheatingRequest,
            default => ReportCategory::Other,
        };

        return 'safety-report:'.$this->sink->report($reporter, $subject, $category, mb_substr($details, 0, 2000))->id;
    }
}
