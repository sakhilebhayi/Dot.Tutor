<?php

namespace Tests\Feature\Classroom;

use App\Domain\Classroom\Support\ClassroomException;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/** QA regression: a classroom refusal (409 "the tutor ended this lesson") is an answer, not an error log entry. */
class ClassroomRefusalLoggingTest extends TestCase
{
    public function test_a_classroom_refusal_is_not_written_to_the_error_log(): void
    {
        Log::spy();

        app(ExceptionHandler::class)->report(new ClassroomException('ended', 'The tutor ended this lesson.', 409));

        Log::shouldNotHaveReceived('error');
        Log::shouldNotHaveReceived('log');
    }
}
