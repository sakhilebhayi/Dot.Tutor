<?php

namespace Tests\Feature\Classroom;

use App\Domain\Classroom\Support\ClassroomException;
use App\Domain\Marketplace\Exceptions\MarketplaceException;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/** QA regression: refusals (409 "the tutor ended this lesson", "that group lesson is full") are answers, not error log entries. */
class ClassroomRefusalLoggingTest extends TestCase
{
    public function test_a_classroom_refusal_is_not_written_to_the_error_log(): void
    {
        Log::spy();

        app(ExceptionHandler::class)->report(new ClassroomException('ended', 'The tutor ended this lesson.', 409));

        Log::shouldNotHaveReceived('error');
        Log::shouldNotHaveReceived('log');
    }

    public function test_a_marketplace_refusal_is_not_logged_but_a_server_fault_is(): void
    {
        Log::spy();

        app(ExceptionHandler::class)->report(new MarketplaceException('full', 'That group lesson is full.', 409));
        Log::shouldNotHaveReceived('error');

        app(ExceptionHandler::class)->report(new MarketplaceException('broken', 'Something failed.', 500));
        Log::shouldHaveReceived('error')->once();
    }
}
