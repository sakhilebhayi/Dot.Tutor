<?php

namespace App\Domain\Classroom\Http\Controllers;

use App\Domain\Classroom\Contracts\FollowUpAssigner;
use App\Domain\Classroom\Contracts\SummaryDrafter;
use App\Domain\Classroom\Models\Classroom;
use App\Domain\Classroom\Services\AfterLesson;
use App\Domain\Classroom\Services\ClassroomAccess;
use App\Domain\Classroom\Services\ClassroomService;
use App\Domain\Classroom\Services\IceConfig;
use App\Domain\Classroom\Services\LessonFiles;
use App\Domain\Classroom\Support\ClassroomException;
use App\Enums\SessionStatus;
use App\Http\Controllers\Controller;
use App\Models\TutorProfile;
use App\Models\TutorSession;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * The classroom's pages: my live lessons, the lesson's room (pre-join check, then the call), the after-lesson page,
 * and the forms around them. The tutor sees them in the tutor workspace; learners and guardians in the learner shell.
 */
class ClassroomPageController extends Controller
{
    public function __construct(
        private readonly ClassroomAccess $access,
        private readonly ClassroomService $rooms,
    ) {}

    public function index(Request $request): View
    {
        $user = $request->user();
        $profileId = TutorProfile::query()->where('user_id', $user->id)->value('id');
        $statuses = [SessionStatus::Confirmed->value, SessionStatus::InProgress->value, SessionStatus::Completed->value];

        $sessions = TutorSession::query()->with(['subject', 'tutorProfile.user', 'student'])
            ->whereIn('status', $statuses)
            ->whereBetween('starts_at', [now()->subDays(7), now()->addDays(14)])
            ->where(function ($q) use ($user, $profileId) {
                $q->where('student_id', $user->id)
                    ->orWhereHas('participants', fn ($p) => $p->where('learner_id', $user->id)->whereIn('status', ClassroomAccess::seatStatesThatCount()));
                if ($profileId) {
                    $q->orWhere('tutor_profile_id', $profileId);
                }
            })
            ->orderBy('starts_at')->orderBy('id')->get();

        $upcoming = $sessions->filter(fn (TutorSession $s) => now()->lte($this->access->closesAt($s)) && $s->status !== SessionStatus::Completed->value)->values();
        $past = $sessions->diff($upcoming)->sortByDesc('starts_at')->values();

        return view('classroom::index', [
            'shell' => $profileId ? 'workspace' : 'learner',
            'upcoming' => $upcoming,
            'past' => $past,
            'access' => $this->access,
            'me' => $user,
        ]);
    }

    /** The lesson's room: who may come in, the pre-join check, then the call (driven by the room script). */
    public function show(Request $request, TutorSession $tutorSession, IceConfig $ice): View
    {
        $user = $request->user();
        $classroom = $this->rooms->for($tutorSession);
        $role = $this->access->roleOf($user, $tutorSession, $classroom);
        abort_if($role === null, 403, 'Only the tutor and the learners booked into this lesson can open its classroom.');

        $admission = $this->access->admit($user, $tutorSession, $classroom);
        $tutorSession->loadMissing(['subject', 'tutorProfile.user', 'student']);
        $iceInfo = $ice->forUser($user->id);

        return view('classroom::room', [
            'session' => $tutorSession,
            'classroom' => $classroom,
            'role' => $role,
            'admission' => $admission,
            'approvals' => $this->access->approvals($tutorSession, $classroom),
            'people' => $this->people($classroom),
            'relay' => $iceInfo['relay'],
            'stun' => $iceInfo['stun'],
            'title' => $this->title($tutorSession),
            'shell' => $role === 'tutor' ? 'workspace' : 'learner',
            'config' => [
                'role' => $role,
                'me' => $user->name,
                'maxInRoom' => (int) config('classroom.max_in_room'),
                'externalUrl' => $classroom->external_url,
                'closesAt' => $this->access->closesAt($tutorSession)->toIso8601String(),
                'urls' => [
                    'join' => route('classroom.api.join', $tutorSession),
                    'poll' => route('classroom.api.poll', $tutorSession),
                    'signal' => route('classroom.api.signal', $tutorSession),
                    'leave' => route('classroom.api.leave', $tutorSession),
                    'tutor' => route('classroom.api.tutor', $tutorSession),
                    'end' => route('classroom.api.end', $tutorSession),
                    'chat' => route('classroom.api.chat', $tutorSession),
                    'report' => route('classroom.api.report', [$tutorSession, '__ID__']),
                    'board' => route('classroom.api.board', $tutorSession),
                    'boardPage' => route('classroom.api.board.page', $tutorSession),
                    'boardSnapshot' => route('classroom.api.board.snapshot', $tutorSession),
                    'notes' => route('classroom.api.notes', $tutorSession),
                    'upload' => route('classroom.api.upload', $tutorSession),
                    'after' => route('classroom.after', $tutorSession),
                ],
            ],
        ]);
    }

    public function after(Request $request, TutorSession $tutorSession, AfterLesson $after, LessonFiles $files, FollowUpAssigner $followUps, SummaryDrafter $drafter): View
    {
        $user = $request->user();
        $classroom = $this->rooms->for($tutorSession);
        $role = $this->access->roleOf($user, $tutorSession, $classroom);
        abort_if($role === null, 403);
        $tutorSession->loadMissing(['subject', 'tutorProfile.user', 'student']);

        return view('classroom::after', [
            'session' => $tutorSession,
            'classroom' => $classroom,
            'role' => $role,
            'title' => $this->title($tutorSession),
            'shell' => $role === 'tutor' ? 'workspace' : 'learner',
            'attendance' => $after->attendance($classroom),
            'files' => $files->list($classroom),
            'learners' => User::query()->whereIn('id', $classroom->learnerIds())->orderBy('name')->get(['id', 'name']),
            'assignable' => $role === 'tutor' ? $followUps->assignable($user) : [],
            'canComplete' => $role === 'tutor' && $after->canComplete($classroom),
            'drafterAvailable' => $drafter->available(),
            'statusLabel' => $tutorSession->statusEnum()->label(),
        ]);
    }

    // ── Forms ───────────────────────────────────────────────────────────────

    /** The tutor's own meeting link, offered when browsers cannot connect directly. https only. */
    public function fallbackLink(Request $request, TutorSession $tutorSession): RedirectResponse
    {
        $classroom = $this->tutorClassroom($request, $tutorSession);
        $data = $request->validate([
            'external_url' => ['nullable', 'string', 'max:500', 'url:https', function ($attribute, $value, $fail) {
                $host = parse_url((string) $value, PHP_URL_HOST);
                if ($value && (! $host || filter_var($host, FILTER_VALIDATE_IP) || ! str_contains($host, '.'))) {
                    $fail('Use the full https link of your meeting room (for example on Google Meet, Teams or Zoom).');
                }
            }],
        ], ['external_url.url' => 'The meeting link must start with https://.']);
        $classroom->forceFill(['external_url' => $data['external_url'] ?: null])->save();

        return back()->with('success', $data['external_url'] ? 'Your meeting link is saved. Learners see it if the classroom cannot connect.' : 'The meeting link is removed.');
    }

    public function requestApproval(Request $request, TutorSession $tutorSession): RedirectResponse
    {
        $classroom = $this->rooms->for($tutorSession);
        abort_if($this->access->roleOf($request->user(), $tutorSession, $classroom) === null, 403);
        $asked = $this->access->requestApprovals($request->user(), $tutorSession, $classroom);

        return back()->with('success', $asked > 0 ? 'We asked the parent or guardian to approve this live lesson.' : 'There is nothing to ask: the approvals are already given or requested.');
    }

    public function tutorNotes(Request $request, TutorSession $tutorSession, AfterLesson $after): RedirectResponse
    {
        $classroom = $this->tutorClassroom($request, $tutorSession);
        $data = $request->validate(['tutor_notes' => ['nullable', 'string', 'max:20000']]);
        $after->saveTutorNotes($classroom, $request->user(), $data['tutor_notes'] ?? null);

        return back()->with('success', 'Your lesson notes are saved. Only you can see them.');
    }

    public function summary(Request $request, TutorSession $tutorSession, AfterLesson $after): RedirectResponse
    {
        $classroom = $this->tutorClassroom($request, $tutorSession);
        $data = $request->validate(['summary' => ['required', 'string', 'max:8000'], 'publish' => ['nullable', 'boolean']]);
        $after->saveSummary($classroom, $request->user(), $data['summary'], (bool) ($data['publish'] ?? false));

        return back()->with('success', ($data['publish'] ?? false) ? 'The summary is published to the learners.' : 'The summary is saved as a draft. Learners do not see it yet.');
    }

    public function draftSummary(Request $request, TutorSession $tutorSession, SummaryDrafter $drafter): RedirectResponse
    {
        $classroom = $this->tutorClassroom($request, $tutorSession);
        if (! $drafter->available() || ($draft = $drafter->draft($classroom)) === null) {
            return back()->with('error', 'No AI drafting is connected to the classroom. Write the summary yourself.');
        }

        return back()->withInput(['summary' => $draft, 'ai_draft' => 1])->with('success', 'An AI draft is in the box. Check and edit it before you publish.');
    }

    public function complete(Request $request, TutorSession $tutorSession, AfterLesson $after): RedirectResponse
    {
        $classroom = $this->tutorClassroom($request, $tutorSession);
        $counted = $after->complete($classroom, $request->user());

        return back()->with('success', $counted ? 'The lesson is marked as completed.' : 'This lesson was already completed.');
    }

    public function assign(Request $request, TutorSession $tutorSession, AfterLesson $after): RedirectResponse
    {
        $classroom = $this->tutorClassroom($request, $tutorSession);
        $data = $request->validate([
            'assessment_id' => ['required', 'integer'],
            'learner_id' => ['required', 'integer', Rule::in($classroom->learnerIds())],
            'due_at' => ['nullable', 'date', 'after:now'],
        ], ['learner_id.in' => 'Pick a learner from this lesson.']);
        $after->assign($classroom, $request->user(), (int) $data['assessment_id'], (int) $data['learner_id'], $data['due_at'] ?? null);

        return back()->with('success', 'The follow-up work is assigned. The learner finds it in Practice.');
    }

    // ── Helpers ─────────────────────────────────────────────────────────────

    private function tutorClassroom(Request $request, TutorSession $tutorSession): Classroom
    {
        $classroom = $this->rooms->for($tutorSession);
        if ($this->access->roleOf($request->user(), $tutorSession, $classroom) !== 'tutor') {
            throw new ClassroomException('tutor_only', 'Only the tutor of this lesson can do that.', 403);
        }

        return $classroom;
    }

    public function title(TutorSession $session): string
    {
        return ($session->subject?->name ?? 'Lesson').($session->isGroup() ? ' group lesson' : ' lesson');
    }

    /** @return list<array{name: string, role: string}> */
    private function people(Classroom $classroom): array
    {
        $rows = [];
        if ($tutorId = $classroom->tutorUserId()) {
            $rows[] = ['name' => (string) User::query()->whereKey($tutorId)->value('name'), 'role' => 'Tutor'];
        }
        foreach (User::query()->whereIn('id', $classroom->learnerIds())->orderBy('name')->get(['id', 'name']) as $learner) {
            $rows[] = ['name' => $learner->name, 'role' => 'Learner'];
        }

        return $rows;
    }
}
