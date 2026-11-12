<?php

namespace App\Domain\Community\Http\Controllers;

use App\Domain\Community\Http\Concerns\AnswersBoth;
use App\Domain\Community\Models\GroupMember;
use App\Domain\Community\Models\GroupTask;
use App\Domain\Community\Models\RevisionItem;
use App\Domain\Community\Models\Room;
use App\Domain\Community\Models\StudyGroup;
use App\Domain\Community\Models\StudySession;
use App\Domain\Community\Services\Calendar;
use App\Domain\Community\Services\RoomAccess;
use App\Domain\Community\Services\StudyGroups;
use App\Domain\Community\Support\Names;
use App\Domain\Learning\Models\LibraryResource;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class GroupController extends Controller
{
    use AnswersBoth;

    public function __construct(
        private readonly StudyGroups $groups,
        private readonly RoomAccess $access,
    ) {}

    public function store(Request $request, Room $room): RedirectResponse|JsonResponse
    {
        $size = config('community.group_size');
        $data = $request->validate([
            'name' => ['required', 'string', 'min:3', 'max:120'],
            'topic' => ['nullable', 'string', 'max:160'],
            'exam_focus' => ['nullable', 'string', 'max:160'],
            'schedule_note' => ['nullable', 'string', 'max:200'],
            'size_cap' => ['required', 'integer', 'min:'.$size['min'], 'max:'.$size['max']],
            'join_rule' => ['required', Rule::in(Room::JOIN_RULES)],
        ]);

        $group = $this->groups->create($request->user(), $room, $data);

        return $this->wantsJson($request)
            ? response()->json(['group' => $this->groupJson($group, true)], 201)
            : redirect()->route('rooms.groups.show', $group)->with('flash.success', 'Your study group is ready.');
    }

    public function show(Request $request, StudyGroup $group): View|JsonResponse
    {
        $user = $request->user();
        $group->load(['room.subject', 'room.level.stage', 'room.curriculum', 'owner']);
        abort_unless($this->access->canRead($user, $group->room), 404);

        $isMember = $this->groups->isMember($user, $group);
        $isOrganiser = $this->groups->isOrganiser($user, $group);
        $membership = GroupMember::query()->where('group_id', $group->id)->where('user_id', $user->id)->first();

        $members = $group->members()->with('user')->where('status', GroupMember::ACTIVE)->orderByRaw("case when role = 'owner' then 0 else 1 end")->orderBy('id')->get();
        $sessions = $group->sessions()->with('attendance')->where('starts_at', '>', now()->subDays(14))->get();
        $resources = $isMember ? $group->resources()->with(['resource', 'addedBy'])->get() : collect();
        $revision = $isMember ? $group->revisionItems()->with('ticks.user')->get() : collect();
        $tasks = $isMember ? $group->tasks()->with('assignees.user')->get() : collect();
        $pending = $isOrganiser ? $group->members()->with('user')->where('status', GroupMember::PENDING)->get() : collect();

        if ($this->wantsJson($request)) {
            return response()->json([
                'group' => $this->groupJson($group, $isOrganiser),
                'membership' => $membership ? ['role' => $membership->role, 'status' => $membership->status] : null,
                'members' => $members->map(fn ($m) => ['id' => $m->user_id, 'name' => Names::short($m->user), 'role' => $m->role])->all(),
                'sessions' => $sessions->map(fn (StudySession $s) => $this->sessionJson($s))->all(),
                'resources' => $resources->map(fn ($r) => ['id' => $r->library_resource_id, 'title' => $r->resource?->title, 'note' => $r->note, 'added_by' => Names::short($r->addedBy)])->all(),
                'revision' => $revision->map(fn (RevisionItem $i) => ['id' => $i->id, 'title' => $i->title, 'done_by' => $i->ticks->map(fn ($t) => Names::short($t->user))->all()])->all(),
                'tasks' => $tasks->map(fn (GroupTask $t) => ['id' => $t->id, 'title' => $t->title, 'due_on' => $t->due_on?->toDateString(),
                    'assignees' => $t->assignees->map(fn ($a) => ['name' => Names::short($a->user), 'done_at' => $a->done_at?->toIso8601String(), 'note' => $a->done_note])->all()])->all(),
            ]);
        }

        return view('community.group', [
            'group' => $group,
            'room' => $group->room,
            'isMember' => $isMember,
            'isOrganiser' => $isOrganiser,
            'membership' => $membership,
            'members' => $members,
            'sessions' => $sessions,
            'resources' => $resources,
            'revision' => $revision,
            'tasks' => $tasks,
            'pending' => $pending,
            'myResources' => $isMember ? LibraryResource::query()->where('status', 'published')
                ->where(fn ($q) => $q->where('owner_id', $user->id)->orWhere('subject_id', $group->room->subject_id))
                ->orderByDesc('id')->limit(30)->get() : collect(),
            'timezone' => config('community.timezone'),
            'step' => $this->access->band($user) === 'child' ? 'young' : 'standard',
        ]);
    }

    public function join(Request $request, StudyGroup $group): RedirectResponse|JsonResponse
    {
        $result = $this->groups->join($request->user(), $group, $request->input('code'));
        $message = $result['status'] === 'pending' ? 'Your request is with the organiser.' : 'You joined '.$group->name.'.';

        return $this->wantsJson($request)
            ? response()->json(['status' => $result['status'], 'message' => $message])
            : redirect()->route('rooms.groups.show', $group)->with('flash.success', $message);
    }

    public function leave(Request $request, StudyGroup $group): RedirectResponse|JsonResponse
    {
        $this->groups->leave($request->user(), $group);

        return $this->wantsJson($request) ? response()->json(['left' => true])
            : redirect()->route('rooms.show', [$group->room_id, 'tab' => 'groups'])->with('flash.success', 'You left the group.');
    }

    public function close(Request $request, StudyGroup $group): RedirectResponse|JsonResponse
    {
        $this->groups->close($request->user(), $group);

        return $this->wantsJson($request) ? response()->json(['closed' => true])
            : redirect()->route('rooms.show', [$group->room_id, 'tab' => 'groups'])->with('flash.success', 'The group is closed.');
    }

    public function decide(Request $request, StudyGroup $group, GroupMember $member): RedirectResponse|JsonResponse
    {
        $request->validate(['accept' => ['required', 'boolean']]);
        $member = $this->groups->decide($request->user(), $group, $member, $request->boolean('accept'));

        return $this->wantsJson($request) ? response()->json(['status' => $member->status])
            : back(303)->with('flash.success', $request->boolean('accept') ? 'Accepted.' : 'Declined.');
    }

    public function removeMember(Request $request, StudyGroup $group, GroupMember $member): RedirectResponse|JsonResponse
    {
        $this->groups->removeMember($request->user(), $group, $member);

        return $this->wantsJson($request) ? response()->json(['removed' => true]) : back(303)->with('flash.success', 'Removed from the group.');
    }

    public function schedule(Request $request, StudyGroup $group): RedirectResponse|JsonResponse
    {
        $data = $this->validateSession($request);
        $session = $this->groups->schedule($request->user(), $group->room, $group, $data);

        return $this->wantsJson($request) ? response()->json(['session' => $this->sessionJson($session)], 201)
            : redirect()->route('rooms.groups.show', $group)->with('flash.success', 'Session scheduled. Add it to your calendar from the list.');
    }

    public function cancelSession(Request $request, StudySession $session): RedirectResponse|JsonResponse
    {
        $this->groups->cancelSession($request->user(), $session);

        return $this->wantsJson($request) ? response()->json(['cancelled' => true]) : back(303)->with('flash.success', 'Session cancelled.');
    }

    public function attendance(Request $request, StudySession $session): RedirectResponse|JsonResponse
    {
        $data = $request->validate(['attended' => ['array'], 'attended.*' => ['integer']]);
        $count = $this->groups->markAttendance($request->user(), $session, $data['attended'] ?? []);

        return $this->wantsJson($request) ? response()->json(['attended' => $count]) : back(303)->with('flash.success', 'Attendance saved.');
    }

    /** The session as an .ics file for the person's own calendar. */
    public function calendar(Request $request, StudySession $session, Calendar $calendar): Response
    {
        $room = $session->group_id ? $session->group->room : $session->room;
        abort_unless($room && $this->access->canRead($request->user(), $room), 404);
        $url = $session->group_id ? route('rooms.groups.show', $session->group_id) : route('rooms.show', [$room, 'tab' => 'groups']);

        return response($calendar->ics($session, $url), 200, [
            'Content-Type' => 'text/calendar; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="dot-tutor-session-'.$session->id.'.ics"',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    public function shareResource(Request $request, StudyGroup $group): RedirectResponse|JsonResponse
    {
        $data = $request->validate(['library_resource_id' => ['required', 'integer'], 'note' => ['nullable', 'string', 'max:200']]);
        $this->groups->shareResource($request->user(), $group, (int) $data['library_resource_id'], $data['note'] ?? null);

        return $this->wantsJson($request) ? response()->json(['shared' => true], 201) : back(303)->with('flash.success', 'Shared with the group.');
    }

    public function addRevisionItem(Request $request, StudyGroup $group): RedirectResponse|JsonResponse
    {
        $data = $request->validate(['title' => ['required', 'string', 'min:2', 'max:160'], 'topic_id' => ['nullable', 'integer', 'exists:academic_topics,id']]);
        $item = $this->groups->addRevisionItem($request->user(), $group, $data['title'], $data['topic_id'] ?? null);

        return $this->wantsJson($request) ? response()->json(['item' => ['id' => $item->id, 'title' => $item->title]], 201) : back(303)->with('flash.success', 'Added to the revision list.');
    }

    public function tick(Request $request, RevisionItem $item): RedirectResponse|JsonResponse
    {
        $this->groups->tick($request->user(), $item, $request->boolean('done', true));

        return $this->wantsJson($request) ? response()->json(['done' => $request->boolean('done', true)]) : back(303);
    }

    public function addTask(Request $request, StudyGroup $group): RedirectResponse|JsonResponse
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'min:2', 'max:160'],
            'details' => ['nullable', 'string', 'max:2000'],
            'due_on' => ['nullable', 'date_format:Y-m-d'],
            'assignees' => ['array'],
            'assignees.*' => ['integer'],
        ]);
        $task = $this->groups->addTask($request->user(), $group, $data['title'], $data['details'] ?? null, $data['due_on'] ?? null, $data['assignees'] ?? []);

        return $this->wantsJson($request) ? response()->json(['task' => ['id' => $task->id, 'title' => $task->title]], 201) : back(303)->with('flash.success', 'Task added.');
    }

    public function markTask(Request $request, GroupTask $task): RedirectResponse|JsonResponse
    {
        $data = $request->validate(['done' => ['required', 'boolean'], 'note' => ['nullable', 'string', 'max:200']]);
        $assignee = $this->groups->markTask($request->user(), $task, (bool) $data['done'], $data['note'] ?? null);

        return $this->wantsJson($request) ? response()->json(['done_at' => $assignee->done_at?->toIso8601String()]) : back(303);
    }

    /** @return array{title: string, starts_at: string, timezone: string, duration_minutes: int, where_note?: ?string} */
    private function validateSession(Request $request): array
    {
        return $request->validate([
            'title' => ['required', 'string', 'min:3', 'max:160'],
            'starts_at' => ['required', 'date_format:Y-m-d\TH:i'],
            'timezone' => ['required', 'timezone:all'],
            'duration_minutes' => ['required', 'integer', 'min:15', 'max:480'],
            'where_note' => ['nullable', 'string', 'max:200'],
        ]);
    }

    /** @return array<string, mixed> */
    private function groupJson(StudyGroup $group, bool $withCode): array
    {
        return [
            'id' => $group->id,
            'room_id' => $group->room_id,
            'name' => $group->name,
            'topic' => $group->topic,
            'exam_focus' => $group->exam_focus,
            'schedule_note' => $group->schedule_note,
            'size_cap' => $group->size_cap,
            'members' => $group->members_count,
            'join_rule' => $group->join_rule,
            'invite_code' => $withCode ? $group->invite_code : null,
            'status' => $group->status,
            'url' => route('rooms.groups.show', $group),
        ];
    }

    /** @return array<string, mixed> */
    private function sessionJson(StudySession $s): array
    {
        return [
            'id' => $s->id,
            'title' => $s->title,
            'starts_at' => $s->starts_at->toIso8601String(),
            'local_start' => $s->localStart()->toIso8601String(),
            'timezone' => $s->timezone,
            'duration_minutes' => $s->duration_minutes,
            'where' => $s->where_note,
            'cancelled' => $s->cancelled_at !== null,
            'calendar_url' => route('rooms.sessions.calendar', $s),
        ];
    }
}
