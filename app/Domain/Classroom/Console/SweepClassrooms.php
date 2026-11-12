<?php

namespace App\Domain\Classroom\Console;

use App\Domain\Classroom\Events\LessonStartingSoon;
use App\Domain\Classroom\Services\ClassroomAccess;
use App\Domain\Classroom\Services\ClassroomService;
use App\Enums\SessionStatus;
use App\Models\TutorSession;
use Illuminate\Console\Command;

/**
 * Every minute (cron-driven scheduler): take lost people out of rooms and close their attendance, delete expired
 * signalling messages, close rooms past their grace time, and announce lessons starting within 15 minutes once
 * (LessonStartingSoon, for the comms domain to turn into reminders).
 */
class SweepClassrooms extends Command
{
    protected $signature = 'classroom:sweep';

    protected $description = 'Tidy live classrooms (lost people, expired signals, closed rooms) and announce lessons starting soon';

    public function handle(ClassroomService $rooms, ClassroomAccess $access): int
    {
        $result = $rooms->sweep($access);

        $announced = 0;
        $soon = TutorSession::query()->where('status', SessionStatus::Confirmed->value)
            ->whereBetween('starts_at', [now(), now()->addMinutes(15)])->orderBy('id')->get();
        foreach ($soon as $session) {
            $classroom = $rooms->for($session);
            if ($classroom->reminder_emitted_at !== null) {
                continue;
            }
            $classroom->forceFill(['reminder_emitted_at' => now()])->save();
            LessonStartingSoon::dispatch($classroom, ['starts_at' => $session->starts_at->toIso8601String(), 'url' => route('classroom.show', $session)]);
            $announced++;
        }

        $this->info("lost {$result['lost']}, signals {$result['signals']}, closed {$result['closed']}, announced {$announced}");

        return self::SUCCESS;
    }
}
