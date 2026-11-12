<?php

namespace App\Domain\Comms\Http\Controllers;

use App\Domain\Comms\Calendar\LessonCalendar;
use App\Domain\Comms\Contracts\MinorRouting;
use App\Domain\Marketplace\Models\TutorSessionParticipant;
use App\Http\Controllers\Controller;
use App\Models\TutorSession;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Download a lesson as an .ics file. Only the people in the booking may: the tutor, the learner (or a seat holder in
 * a group lesson), and the linked guardians of a minor learner. Anyone else gets 404 (the lesson's existence is not
 * confirmed).
 */
class CalendarController extends Controller
{
    public function lesson(Request $request, int $session, LessonCalendar $calendar, MinorRouting $minors): Response
    {
        $lesson = TutorSession::query()->with('tutorProfile')->find($session);
        abort_if($lesson === null || ! $this->mayView($request->user(), $lesson, $minors), 404);

        return response($calendar->forSession($lesson, fn (User $u) => $minors->isMinor($u)), 200, [
            'Content-Type' => 'text/calendar; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="'.$calendar->filename($lesson).'"',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    private function mayView(User $user, TutorSession $lesson, MinorRouting $minors): bool
    {
        if ($lesson->tutorProfile?->user_id === $user->id) {
            return true;
        }

        $learnerIds = $lesson->isGroup()
            ? TutorSessionParticipant::query()->where('tutor_session_id', $lesson->id)->whereNotIn('status', ['cancelled'])->pluck('learner_id')->map(fn ($id) => (int) $id)->all()
            : array_filter([(int) $lesson->student_id]);

        if (in_array($user->id, $learnerIds, true)) {
            return true;
        }

        foreach (User::query()->whereIn('id', $learnerIds)->get() as $learner) {
            if ($minors->guardiansOf($learner)->contains('id', $user->id)) {
                return true;
            }
        }

        return false;
    }
}
