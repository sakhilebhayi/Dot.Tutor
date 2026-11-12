<?php

namespace App\Domain\Student\Http\Controllers;

use App\Domain\Ai\Services\AiGateway;
use App\Domain\Student\Services\LearnerAgenda;
use App\Domain\Student\Services\LearnerContext;
use App\Domain\Student\Services\NeedReader;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** The learner's home: what is next, and "tell us what you need". */
class HomeController extends Controller
{
    public function __invoke(Request $request, LearnerContext $context, LearnerAgenda $agenda): View
    {
        $user = $request->user();

        $lessons = $agenda->upcomingLessons($user);
        $seats = $agenda->upcomingSeats($user);
        $courses = $agenda->coursesInProgress($user);
        $work = $agenda->workDue($user);
        $toReview = $agenda->lessonsToReview($user);

        // The one next thing: a lesson that needs the learner (pay) first, then the soonest lesson, then due work,
        // then the course to resume. Null when there is nothing at all.
        $next = null;
        if ($pay = $lessons->firstWhere('status', 'awaiting_payment')) {
            $next = ['kind' => 'pay', 'lesson' => $pay];
        } elseif ($soon = $lessons->first(fn ($s) => in_array($s->status, ['confirmed', 'in_progress'], true))) {
            $next = ['kind' => 'lesson', 'lesson' => $soon];
        } elseif ($work !== []) {
            $next = ['kind' => 'work', 'work' => $work[0]];
        } elseif ($courses !== []) {
            $next = ['kind' => 'course', 'course' => $courses[0]];
        } elseif ($lessons->isNotEmpty()) {
            $next = ['kind' => 'lesson', 'lesson' => $lessons->first()];
        }

        return view('student.home', [
            'ctx' => $context->for($user),
            'next' => $next,
            'lessons' => $lessons,
            'seats' => $seats,
            'courses' => $courses,
            'work' => $work,
            'toReview' => $toReview,
            'insights' => $agenda->insights($user),
            'questions' => $agenda->unansweredInMyRooms($user),
            'aiConfigured' => class_exists(AiGateway::class) && app(AiGateway::class)->configured(),
        ]);
    }

    /** "Tell us what you need": read the words, then show the explained matches with what was understood. */
    public function need(Request $request, NeedReader $reader): RedirectResponse
    {
        $text = (string) $request->validate(['need' => ['required', 'string', 'min:2', 'max:500']])['need'];
        $read = $reader->read($text, $request->user());

        return redirect()->route('marketplace.find', $read['query'])
            ->with('student.understood', $read['understood'])
            ->with('student.need_notes', $read['notes'])
            ->with('student.found_subject', $read['found_subject']);
    }
}
