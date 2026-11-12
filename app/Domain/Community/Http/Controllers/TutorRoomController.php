<?php

namespace App\Domain\Community\Http\Controllers;

use App\Domain\Academic\Models\Curriculum;
use App\Domain\Academic\Models\Language;
use App\Domain\Community\Http\Concerns\AnswersBoth;
use App\Domain\Community\Models\Room;
use App\Domain\Community\Models\RoomMember;
use App\Domain\Community\Services\RoomAccess;
use App\Domain\Community\Services\StudyGroups;
use App\Domain\Community\Services\TutorRooms;
use App\Domain\Money\CheckoutStart;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class TutorRoomController extends Controller
{
    use AnswersBoth;

    public function __construct(
        private readonly TutorRooms $tutorRooms,
        private readonly RoomAccess $access,
    ) {}

    /** The tutor's rooms and the form to start one (workspace shell). */
    public function index(Request $request): View
    {
        $user = $request->user();
        $rooms = Room::query()->with(['subject', 'level.stage', 'curriculum'])->where('kind', Room::TUTOR)->where('owner_id', $user->id)
            ->orderByRaw("case when status = 'active' then 0 else 1 end")->orderByDesc('id')->get();
        $pending = RoomMember::query()->whereIn('room_id', $rooms->pluck('id'))->where('status', RoomMember::PENDING)->count();

        return view('community.teach', [
            'rooms' => $rooms,
            'pending' => $pending,
            'approved' => $this->access->isApprovedTutor($user),
            'curricula' => Curriculum::query()->where('is_active', true)->orderBy('sort')
                ->with(['levels' => fn ($q) => $q->where('is_active', true)->orderBy('position')->with(['offerings' => fn ($o) => $o->where('is_active', true)->with('subject')])])->get(),
            'languages' => Language::query()->where('is_active', true)->orderBy('sort')->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse|JsonResponse
    {
        $cap = config('community.room_capacity');
        $data = $request->validate([
            'curriculum_subject_level_id' => ['required', 'integer'],
            'audience' => ['required', Rule::in([Room::UNDER_18, Room::ADULT])],
            'title' => ['required', 'string', 'min:5', 'max:160'],
            'description' => ['nullable', 'string', 'max:4000'],
            'language' => ['nullable', 'string', 'max:20', 'exists:languages,code'],
            'join_rule' => ['required', Rule::in(Room::JOIN_RULES)],
            'capacity' => ['nullable', 'integer', 'min:'.$cap['min'], 'max:'.$cap['max']],
            'price_minor' => ['nullable', 'integer', 'min:0', 'max:10000000'],
            'price_rand' => ['nullable', 'numeric', 'min:0', 'max:100000'],
        ]);
        if (! isset($data['price_minor']) && isset($data['price_rand'])) {
            $data['price_minor'] = (int) round(((float) $data['price_rand']) * 100);
        }

        $room = $this->tutorRooms->create($request->user(), $data);

        return $this->wantsJson($request)
            ? response()->json(['room' => $this->roomJson($room) + ['invite_code' => $room->invite_code]], 201)
            : redirect()->route('rooms.show', $room)->with('flash.success', 'Your room is open.');
    }

    public function enrol(Request $request, Room $room): RedirectResponse|JsonResponse
    {
        $result = $this->tutorRooms->enrol($request->user(), $room, $request->input('code'), $request->header('Idempotency-Key') ?: $request->input('idempotency_key'));

        if ($this->wantsJson($request)) {
            return response()->json([
                'status' => $result['status'],
                'message' => $result['message'],
                'checkout' => isset($result['checkout']) ? $result['checkout']->toApi() : null,
            ], $result['status'] === 'active' ? 200 : 202);
        }

        if (isset($result['checkout']) && $result['checkout']->status === CheckoutStart::REDIRECT && $result['checkout']->redirectUrl) {
            return redirect()->away($result['checkout']->redirectUrl);
        }

        return redirect()->route('rooms.show', $room)->with($result['status'] === 'active' ? 'flash.success' : 'flash.banner', $result['message']);
    }

    public function decide(Request $request, Room $room, RoomMember $member): RedirectResponse|JsonResponse
    {
        $request->validate(['accept' => ['required', 'boolean']]);
        $member = $this->tutorRooms->decide($request->user(), $room, $member, $request->boolean('accept'));

        return $this->wantsJson($request) ? response()->json(['status' => $member->status]) : back(303)->with('flash.success', $request->boolean('accept') ? 'Accepted.' : 'Declined.');
    }

    public function removeMember(Request $request, Room $room, RoomMember $member): RedirectResponse|JsonResponse
    {
        $this->tutorRooms->removeMember($request->user(), $room, $member);

        return $this->wantsJson($request) ? response()->json(['removed' => true]) : back(303)->with('flash.success', 'Removed from the room.');
    }

    public function archive(Request $request, Room $room): RedirectResponse|JsonResponse
    {
        $this->tutorRooms->archive($request->user(), $room);

        return $this->wantsJson($request) ? response()->json(['archived' => true]) : redirect()->route('teach.rooms')->with('flash.success', 'Room archived.');
    }

    public function inviteCode(Request $request, Room $room): RedirectResponse|JsonResponse
    {
        $code = $this->tutorRooms->newInviteCode($request->user(), $room);

        return $this->wantsJson($request) ? response()->json(['invite_code' => $code]) : back(303)->with('flash.success', 'New invitation code: '.$code);
    }

    /** A workshop in a tutor room. */
    public function schedule(Request $request, Room $room, StudyGroups $groups): RedirectResponse|JsonResponse
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'min:3', 'max:160'],
            'starts_at' => ['required', 'date_format:Y-m-d\TH:i'],
            'timezone' => ['required', 'timezone:all'],
            'duration_minutes' => ['required', 'integer', 'min:15', 'max:480'],
            'where_note' => ['nullable', 'string', 'max:200'],
        ]);
        $session = $groups->schedule($request->user(), $room, null, $data);

        return $this->wantsJson($request)
            ? response()->json(['session' => ['id' => $session->id, 'starts_at' => $session->starts_at->toIso8601String(), 'calendar_url' => route('rooms.sessions.calendar', $session)]], 201)
            : redirect()->route('rooms.show', [$room, 'tab' => 'groups'])->with('flash.success', 'Workshop scheduled.');
    }
}
