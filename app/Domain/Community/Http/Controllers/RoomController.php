<?php

namespace App\Domain\Community\Http\Controllers;

use App\Domain\Academic\Contracts\AcademicScope;
use App\Domain\Academic\Models\AcademicLevel;
use App\Domain\Academic\Models\Curriculum;
use App\Domain\Academic\Models\Language;
use App\Domain\Academic\Models\SubjectOffering;
use App\Domain\Community\Http\Concerns\AnswersBoth;
use App\Domain\Community\Models\Post;
use App\Domain\Community\Models\Room;
use App\Domain\Community\Models\RoomMember;
use App\Domain\Community\Models\StudyGroup;
use App\Domain\Community\Models\StudySession;
use App\Domain\Community\Services\Activity;
use App\Domain\Community\Services\Indicators;
use App\Domain\Community\Services\Recommendations;
use App\Domain\Community\Services\RoomAccess;
use App\Domain\Community\Services\Rooms;
use App\Domain\Safety\Contracts\GuardianApprovals;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\View\View;

class RoomController extends Controller
{
    use AnswersBoth;

    public const TABS = ['questions', 'discussions', 'material', 'tutors', 'groups'];

    public function __construct(
        private readonly Rooms $rooms,
        private readonly RoomAccess $access,
    ) {}

    /** My rooms: the profile's level and subjects, joined rooms, indicators, and a way to browse other levels. */
    public function index(Request $request, Indicators $indicators): View|JsonResponse
    {
        $user = $request->user();
        $mine = $this->rooms->mine($user);
        $audience = $this->access->audienceFor($user);

        if ($this->wantsJson($request)) {
            return response()->json([
                'audience' => $audience,
                'level' => $mine['level'] ? ['id' => $mine['level']->id, 'name' => $mine['level']->name, 'curriculum' => $mine['level']->curriculum?->name] : null,
                'profile_rooms' => $mine['hub']->map(fn ($row) => $this->hubRow($row, $mine['level'], $audience))->all(),
                'joined_rooms' => $mine['joined']->map(fn (Room $r) => $this->roomJson($r))->all(),
                'indicators' => $indicators->forUser($user),
            ]);
        }

        $curricula = Curriculum::query()->where('is_active', true)->orderBy('sort')->orderBy('name')
            ->with(['levels' => fn ($q) => $q->where('is_active', true)->orderBy('position')->with('stage')])->get();

        return view('community.index', [
            'mine' => $mine,
            'audience' => $audience,
            'curricula' => $curricula,
            'indicators' => $indicators->forUser($user),
            'counts' => $this->rooms->questionCounts($mine['joined']->pluck('id')->all()),
            'step' => $this->access->band($user) === 'child' ? 'young' : 'standard',
        ]);
    }

    /** A stage hub: every subject room of one level for the viewer's audience. */
    public function level(Request $request, AcademicLevel $level): View|JsonResponse
    {
        abort_unless($level->is_active, 404);
        $user = $request->user();
        $audience = $this->audienceParam($request);
        $level->loadMissing(['stage', 'curriculum']);
        $scope = app(AcademicScope::class)->forLearner($user);
        $rows = $this->rooms->hub($level, $audience, $scope?->levelId === $level->id ? $scope->subjectIds : []);

        $tutorRooms = Room::query()->with(['subject', 'owner'])->where('kind', Room::TUTOR)->where('status', 'active')
            ->where('academic_level_id', $level->id)->where('audience', $audience)->orderBy('id')->get()
            ->filter(fn (Room $r) => $this->access->canSeeListing($user, $r))->values();

        if ($this->wantsJson($request)) {
            return response()->json([
                'level' => ['id' => $level->id, 'name' => $level->name, 'stage' => $level->stage?->code, 'curriculum' => $level->curriculum?->name],
                'audience' => $audience,
                'rooms' => $rows->map(fn ($row) => $this->hubRow($row, $level, $audience))->all(),
                'tutor_rooms' => $tutorRooms->map(fn (Room $r) => $this->roomJson($r))->all(),
            ]);
        }

        return view('community.level', [
            'level' => $level,
            'rows' => $rows,
            'audience' => $audience,
            'tutorRooms' => $tutorRooms,
            'canSwitchAudience' => $this->canSwitchAudience($request),
            'step' => $this->access->band($user) === 'child' ? 'young' : 'standard',
        ]);
    }

    /** Open (and, the first time, create) the subject room of an offering. */
    public function open(Request $request, AcademicLevel $level, SubjectOffering $offering): RedirectResponse|JsonResponse
    {
        abort_unless($offering->academic_level_id === $level->id, 404);
        $user = $request->user();
        $room = $this->rooms->subjectRoom($offering, $this->audienceParam($request));
        $this->rooms->authorizeEntry($user, $room);

        return $this->wantsJson($request)
            ? response()->json(['room' => $this->roomJson($room)])
            : redirect()->route('rooms.show', $room);
    }

    public function show(Request $request, Room $room, Recommendations $recommendations, GuardianApprovals $approvals): View|JsonResponse
    {
        $user = $request->user();
        $room->loadMissing(['subject', 'level.stage', 'curriculum', 'owner']);

        if (! $this->access->canRead($user, $room)) {
            if ($room->isTutorRoom() && $this->access->canSeeListing($user, $room)) {
                return $this->listing($request, $room, $approvals);
            }
            $this->rooms->authorizeEntry($user, $room);
            abort(404);
        }

        $tab = in_array($request->query('tab'), self::TABS, true) ? $request->query('tab') : 'questions';
        $lang = $request->query('lang');
        $state = in_array($request->query('state'), ['open', 'resolved', 'all'], true) ? $request->query('state') : 'all';
        $kind = in_array($request->query('kind'), Post::DISCUSSION_KINDS, true) ? $request->query('kind') : null;

        $threads = Post::query()->with('author')->where('room_id', $room->id)->whereNull('parent_id')->visibleTo($user)
            ->when($tab === 'questions', fn ($q) => $q->where('kind', Post::QUESTION)
                ->when($state === 'open', fn ($q) => $q->whereNull('resolved_at'))
                ->when($state === 'resolved', fn ($q) => $q->whereNotNull('resolved_at')))
            ->when($tab === 'discussions', fn ($q) => $q->whereIn('kind', $kind ? [$kind] : Post::DISCUSSION_KINDS))
            ->when($tab === 'material', fn ($q) => $q->where('kind', Post::MATERIAL))
            ->when(is_string($lang) && $lang !== '', fn ($q) => $q->where('original_locale', $lang))
            ->withCount(['children as replies_count' => fn ($q) => $q->where('status', Post::VISIBLE)])
            ->orderByDesc('last_activity_at')->orderByDesc('id');

        $languageCodes = Post::query()->where('room_id', $room->id)->whereNull('parent_id')->where('status', Post::VISIBLE)
            ->distinct()->orderBy('original_locale')->pluck('original_locale')->all();
        $languages = Language::query()->whereIn('code', array_filter([...$languageCodes, $room->language]))->get()->keyBy('code');

        $member = $this->access->membership($user, $room);
        $data = [
            'room' => $room,
            'tab' => $tab,
            'state' => $state,
            'kind' => $kind,
            'lang' => $lang,
            'member' => $member,
            'canParticipate' => $this->access->canParticipate($user, $room),
            'canManage' => $this->access->canManage($user, $room),
            'isTutor' => $this->access->isTutorIn($user, $room),
            'languageCodes' => $languageCodes,
            'languages' => $languages,
            'counts' => $this->rooms->questionCounts([$room->id])[$room->id] ?? ['open' => 0, 'resolved' => 0, 'last' => null],
            'version' => app(Activity::class)->summary($room, $user)['version'],
            'requestHelpUrl' => $recommendations->tutoringRequestUrl($room),
        ];

        if (in_array($tab, ['questions', 'discussions', 'material'], true)) {
            $data['threads'] = $threads->paginate(20)->withQueryString();
        }
        if ($tab === 'material') {
            $data['library'] = $recommendations->materialFor($room);
        }
        if ($tab === 'tutors') {
            $data['tutors'] = $recommendations->tutorsFor($room, $user);
            $data['courses'] = $recommendations->coursesFor($room, $user);
            $data['tutorRooms'] = Room::query()->with('owner')->where('kind', Room::TUTOR)->where('status', 'active')
                ->where('curriculum_subject_level_id', $room->curriculum_subject_level_id)->where('audience', $room->audience)
                ->whereKeyNot($room->id)->orderBy('id')->get();
        }
        if ($tab === 'groups') {
            $data['groups'] = StudyGroup::query()->with('owner')->where('room_id', $room->id)->where('status', 'active')->orderByDesc('id')->get();
            $data['nextSessions'] = StudySession::query()->whereIn('group_id', $data['groups']->pluck('id'))->whereNull('cancelled_at')
                ->where('starts_at', '>', now())->orderBy('starts_at')->get()->groupBy('group_id')->map->first();
            $data['workshops'] = $room->isTutorRoom()
                ? StudySession::query()->where('room_id', $room->id)->where('starts_at', '>', now()->subDay())->orderBy('starts_at')->get()
                : collect();
            $data['pending'] = $data['canManage']
                ? RoomMember::query()->with('user')->where('room_id', $room->id)->where('status', RoomMember::PENDING)->get()
                : collect();
            $data['roster'] = $data['canManage']
                ? RoomMember::query()->with('user')->where('room_id', $room->id)->where('status', RoomMember::ACTIVE)->orderBy('id')->get()
                : collect();
        }

        if ($this->wantsJson($request)) {
            return response()->json([
                'room' => $this->roomJson($room),
                'tab' => $tab,
                'membership' => $member ? ['role' => $member->role, 'status' => $member->status] : null,
                'can_participate' => $data['canParticipate'],
                'languages' => $languageCodes,
                'counts' => ['open_questions' => $data['counts']['open'], 'resolved_questions' => $data['counts']['resolved']],
                'version' => $data['version'],
                'request_tutoring_url' => $data['requestHelpUrl'],
                'threads' => isset($data['threads']) ? collect($data['threads']->items())->map(fn (Post $p) => $this->postJson($p) + ['replies' => $p->replies_count])->all() : null,
                'library' => isset($data['library']) ? $data['library']->map(fn ($r) => ['id' => $r->id, 'title' => $r->title, 'type' => $r->type?->value, 'language' => $r->language, 'url' => route('learning.library.show', $r->id)])->all() : null,
                'tutors' => $data['tutors'] ?? null,
                'courses' => isset($data['courses']) ? $data['courses']->map(fn ($row) => ['id' => $row['course']->id, 'title' => $row['course']->title, 'reasons' => $row['reasons']])->all() : null,
                'groups' => isset($data['groups']) ? $data['groups']->map(fn (StudyGroup $g) => ['id' => $g->id, 'name' => $g->name, 'topic' => $g->topic, 'exam_focus' => $g->exam_focus, 'members' => $g->members_count, 'size_cap' => $g->size_cap, 'join_rule' => $g->join_rule])->all() : null,
            ]);
        }

        $data['step'] = $this->access->band($user) === 'child' ? 'young' : 'standard';

        return view('community.room', $data);
    }

    public function join(Request $request, Room $room): RedirectResponse|JsonResponse
    {
        abort_if($room->isTutorRoom(), 404, 'Use enrol for a tutor room.');
        $member = $this->rooms->joinSubjectRoom($request->user(), $room);

        return $this->wantsJson($request)
            ? response()->json(['membership' => ['role' => $member->role, 'status' => $member->status]])
            : redirect()->route('rooms.show', $room)->with('flash.success', 'You joined '.$room->displayName().'.');
    }

    public function leave(Request $request, Room $room): RedirectResponse|JsonResponse
    {
        $this->rooms->leave($request->user(), $room);

        return $this->wantsJson($request)
            ? response()->json(['left' => true])
            : redirect()->route('rooms.index')->with('flash.success', 'You left '.$room->displayName().'.');
    }

    /** Polling endpoint: a small versioned summary; 304 when the version the caller holds is still current. */
    public function activity(Request $request, Room $room, Activity $activity): JsonResponse|Response
    {
        abort_unless($this->access->canRead($request->user(), $room), 404);
        $summary = $activity->summary($room, $request->user());
        $etag = '"'.$summary['version'].'"';

        if (trim((string) $request->header('If-None-Match')) === $etag) {
            return response('', 304)->header('ETag', $etag)->header('Cache-Control', 'private, no-cache');
        }

        return response()->json($summary)->header('ETag', $etag)->header('Cache-Control', 'private, no-cache');
    }

    public function indicators(Request $request, Indicators $indicators): JsonResponse
    {
        return response()->json(['indicators' => $indicators->forUser($request->user())]);
    }

    /** A tutor room the viewer may enrol in but cannot read yet: its listing and the way in. */
    private function listing(Request $request, Room $room, GuardianApprovals $approvals): View|JsonResponse
    {
        $user = $request->user();
        $member = $this->access->membership($user, $room);
        $approval = $member?->status === RoomMember::AWAITING_GUARDIAN ? $approvals->status($user, 'paid_room', $room) : null;

        if ($this->wantsJson($request)) {
            return response()->json([
                'room' => $this->roomJson($room),
                'membership' => $member ? ['role' => $member->role, 'status' => $member->status] : null,
                'guardian_approval' => $approval,
                'can_read' => false,
            ]);
        }

        return view('community.listing', [
            'room' => $room,
            'member' => $member,
            'approval' => $approval,
            'step' => $this->access->band($user) === 'child' ? 'young' : 'standard',
        ]);
    }

    private function audienceParam(Request $request): string
    {
        $mine = $this->access->audienceFor($request->user());
        $asked = $request->query('audience');

        // Adults may look at rooms for learners under 18 only when the gate lets them in (screened, or operators);
        // the gate is asked again on every room. Minors always stay in their own audience.
        if ($asked === Room::UNDER_18 && $mine === Room::ADULT) {
            return Room::UNDER_18;
        }

        return $mine;
    }

    private function canSwitchAudience(Request $request): bool
    {
        return $this->access->audienceFor($request->user()) === Room::ADULT
            && ($this->access->isOperator($request->user()) || $this->access->isApprovedTutor($request->user()));
    }

    /** @param array<string, mixed> $row */
    private function hubRow(array $row, ?AcademicLevel $level, string $audience): array
    {
        return [
            'offering_id' => $row['offering']->id,
            'subject' => ['id' => $row['offering']->subject_id, 'name' => $row['offering']->subject?->name],
            'room_id' => $row['room']?->id,
            'open_questions' => $row['open_questions'],
            'resolved_questions' => $row['resolved_questions'],
            'last_activity_at' => $row['last_activity_at'] ? (string) $row['last_activity_at'] : null,
            'in_profile' => $row['is_profile_subject'],
            'open_url' => $level ? route('rooms.open', [$level, $row['offering'], 'audience' => $audience]) : null,
        ];
    }
}
