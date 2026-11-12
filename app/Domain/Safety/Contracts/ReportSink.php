<?php

namespace App\Domain\Safety\Contracts;

use App\Domain\Safety\Enums\ReportCategory;
use App\Domain\Safety\Models\Report;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/** Report any model (message, review, resource, course, room post, profile) to the moderation queue. */
interface ReportSink
{
    public function report(User $reporter, Model $reportable, ReportCategory $category, ?string $details = null): Report;
}
