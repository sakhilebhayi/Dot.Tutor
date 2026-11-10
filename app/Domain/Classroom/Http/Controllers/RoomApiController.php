<?php

namespace App\Domain\Classroom\Http\Controllers;

use App\Domain\Classroom\Models\Classroom;
use App\Domain\Classroom\Models\ClassroomAttendee;
use App\Domain\Classroom\Models\ClassroomMessage;
use App\Domain\Classroom\Services\ClassroomAccess;
use App\Domain\Classroom\Services\ClassroomChat;
use App\Domain\Classroom\Services\ClassroomService;
use App\Domain\Classroom\Services\LessonFiles;
use App\Domain\Classroom\Services\SharedNotes;
use App\Domain\Classroom\Services\Whiteboard;
use App\Domain\Classroom\Support\ClassroomException;
use App\Http\Controllers\Controller;
use App\Models\LessonResource;
use App\Models\TutorSession;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Validator;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The room's JSON API. Every call re-checks who may be in the room (server-side); calls made from inside the call
 * also name the browser tab (X-Client-Id), so a second window takes over and the first is told.
 */
class RoomApiController extends Controller
{
    public function __construct(
        private readonly ClassroomAccess $access,
        private readonly ClassroomService $rooms,
    ) {}

    public function join(Request $request, TutorSession $tutorSession): JsonResponse
    {
        $data = $request->validate(['client_id' => ['required', 'string', 'max:40', 'regex:/^[A-Za-z0-9_-]+$/']]);
        $classroom = $this->rooms->for($tutorSession);
        $admission = $this->access->admit($request->user(), $tutorSession, $classroom);
        $result = $this->rooms->join($classroom, $request->user(), $admission, $data['client_id']);
        Cache::put($this->admissionKey($classroom, $request), true, now()->addSeconds(30));

        return response()->json([
            'attendee_id' => $result['attendee']->id,
            'role' => $result['attendee']->role,
            'ice' => $result['ice']['servers'],
            'relay' => $result['ice']['relay'],
            'stun' => $result['ice']['stun'],
            'max_in_room' => (int) config('classroom.max_in_room'),
        ]);
    }

    /**
     * Everything the room needs in one round trip: who is here, signals for me, new chat, new whiteboard ops, the
     * shared notes when they changed, and the files when they changed.
     */
    public function poll(Request $request, TutorSession $tutorSession, ClassroomChat $chat, Whiteboard $board, LessonFiles $files): JsonResponse
    {
        [$classroom, $me] = $this->inCall($request, $tutorSession);
        $q = fn (string $key) => max(0, (int) $request->input($key, 0));

        $this->rooms->touch($me, $request->only(['mic', 'cam', 'sharing', 'quality']));
        $snapshot = $this->rooms->snapshot($classroom, $me, $q('since'));

        $snapshot['chat'] = $chat->since($classroom, $q('chat_since'), $request->user());
        $snapshot['board'] = $board->since($classroom, $q('board_since'));
        if ((int) $request->input('notes_version', -1) !== $classroom->shared_notes_version) {
            $snapshot['notes'] = ['version' => $classroom->shared_notes_version, 'body' => (string) $classroom->shared_notes];
        }
        $latestFile = (int) LessonResource::query()->where('session_id', $tutorSession->id)->max('id');
        if ($latestFile !== $q('files_since')) {
            $snapshot['files'] = $files->list($classroom);
            $snapshot['files_latest'] = $latestFile;
        }
        $snapshot['external_url'] = $classroom->external_url;

        return response()->json($snapshot);
    }

    public function signal(Request $request, TutorSession $tutorSession): JsonResponse
    {
        [$classroom, $me] = $this->inCall($request, $tutorSession, recheck: false);
        $data = $request->validate([
            'to' => ['nullable', 'integer'],
            'type' => ['required', 'string', 'in:offer,answer,candidate'],
            'payload' => ['required', 'string'],
        ]);
        $signal = $this->rooms->signal($classroom, $me, $data['to'] ?? null, $data['type'], $data['payload']);

        return response()->json(['id' => $signal->id], 201);
    }

    public function leave(Request $request, TutorSession $tutorSession): JsonResponse
    {
        $classroom = $this->rooms->for($tutorSession);
        $me = ClassroomAttendee::query()->where('classroom_id', $classroom->id)->where('user_id', $request->user()->id)->first();
        if ($me && ($me->client_id === null || $me->client_id === $request->header('X-Client-Id'))) {
            $this->rooms->leave($classroom, $me);
        }

        return response()->json(['left' => true]);
    }

    public function tutorAction(Request $request, TutorSession $tutorSession): JsonResponse
    {
        [$classroom, $me] = $this->inCall($request, $tutorSession);
        $data = $request->validate(['action' => ['required', 'in:mute,remove'], 'attendee_id' => ['required', 'integer']]);
        $target = ClassroomAttendee::query()->where('classroom_id', $classroom->id)->findOrFail($data['attendee_id']);
        $this->rooms->tutorAction($classroom, $me, $data['action'], $target);

        return response()->json(['done' => $data['action']]);
    }

    public function end(Request $request, TutorSession $tutorSession): JsonResponse
    {
        $classroom = $this->rooms->for($tutorSession);
        if ($this->access->roleOf($request->user(), $tutorSession, $classroom) !== 'tutor') {
            throw new ClassroomException('tutor_only', 'Only the tutor can end the lesson.', 403);
        }
        $this->rooms->end($classroom, $request->user());

        return response()->json(['ended' => true, 'after' => route('classroom.after', $tutorSession)]);
    }

    // ── Collaboration ───────────────────────────────────────────────────────

    public function chat(Request $request, TutorSession $tutorSession, ClassroomChat $chat): JsonResponse
    {
        [$classroom] = $this->inCall($request, $tutorSession);
        $data = $request->validate(['body' => ['required', 'string', 'max:4000']]);
        $message = $chat->send($classroom, $request->user(), $data['body']);

        return response()->json(['message' => $chat->present($message, $request->user())], 201);
    }

    public function reportMessage(Request $request, TutorSession $tutorSession, ClassroomMessage $message, ClassroomChat $chat): JsonResponse
    {
        $classroom = $this->rooms->for($tutorSession);
        abort_unless($message->classroom_id === $classroom->id, 404);
        if ($this->access->roleOf($request->user(), $tutorSession, $classroom) === null) {
            throw new ClassroomException('not_participant', 'Only people in this lesson can report its messages.', 403);
        }
        $data = $request->validate(['category' => ['required', 'string', 'max:40'], 'details' => ['nullable', 'string', 'max:2000']]);
        $report = $chat->report($request->user(), $message, $data['category'], $data['details'] ?? null);

        return response()->json(['report_id' => $report->id, 'message' => 'Thank you. A Dot.Tutor safeguarding officer will look at this.'], 201);
    }

    public function board(Request $request, TutorSession $tutorSession, Whiteboard $board): JsonResponse
    {
        [$classroom] = $this->inCall($request, $tutorSession, recheck: false);
        $data = $request->validate(['page' => ['required', 'integer'], 'kind' => ['required', 'string'], 'data' => ['nullable', 'array']]);
        $op = $board->add($classroom, $request->user(), (int) $data['page'], $data['kind'], $data['data'] ?? []);

        return response()->json(['id' => $op->id], 201);
    }

    public function boardPage(Request $request, TutorSession $tutorSession, Whiteboard $board): JsonResponse
    {
        [$classroom] = $this->inCall($request, $tutorSession);

        return response()->json(['pages' => $board->addPage($classroom)], 201);
    }

    public function boardSnapshot(Request $request, TutorSession $tutorSession, LessonFiles $files): JsonResponse
    {
        [$classroom] = $this->inCall($request, $tutorSession);
        $data = $request->validate(['page' => ['required', 'integer', 'min:1'], 'image' => ['required', 'file']]);
        $resource = $files->storeBoardImage($classroom, $request->user(), $data['image'], (int) $data['page']);

        return response()->json(['id' => $resource->id, 'title' => $resource->title], 201);
    }

    public function notes(Request $request, TutorSession $tutorSession, SharedNotes $notes): JsonResponse
    {
        [$classroom] = $this->inCall($request, $tutorSession);
        $data = $request->validate(['body' => ['present', 'nullable', 'string'], 'version' => ['required', 'integer', 'min:0']]);

        return response()->json($notes->save($classroom, $request->user(), (string) ($data['body'] ?? ''), (int) $data['version']));
    }

    public function upload(Request $request, TutorSession $tutorSession, LessonFiles $files): JsonResponse
    {
        [$classroom] = $this->inCall($request, $tutorSession);
        $validator = Validator::make($request->all(), ['file' => ['required', 'file'], 'title' => ['nullable', 'string', 'max:200']]);
        $data = $validator->validate();
        $resource = $files->upload($classroom, $request->user(), $data['file'], $data['title'] ?? null);

        return response()->json(['id' => $resource->id, 'title' => $resource->title, 'files' => $files->list($classroom)], 201);
    }

    /** A lesson file, for anyone who belongs to the lesson (also after it ended). */
    public function file(Request $request, TutorSession $tutorSession, LessonResource $resource, LessonFiles $files): StreamedResponse
    {
        abort_unless($resource->session_id === $tutorSession->id, 404);
        abort_if($this->access->roleOf($request->user(), $tutorSession) === null, 403);

        return $files->stream($resource);
    }

    // ── Helpers ─────────────────────────────────────────────────────────────

    /**
     * The classroom and my attendee row, refusing anyone who is not in the call from this tab. The full admission
     * (participant, booking state, time window, safeguarding gate) is re-run at most every 30 seconds per person.
     *
     * @return array{0: Classroom, 1: ClassroomAttendee}
     */
    private function inCall(Request $request, TutorSession $tutorSession, bool $recheck = true): array
    {
        $classroom = $this->rooms->for($tutorSession);
        $me = ClassroomAttendee::query()->where('classroom_id', $classroom->id)->where('user_id', $request->user()->id)->first();

        if ($me === null) {
            throw new ClassroomException('not_joined', 'Join the lesson first.', 409);
        }
        if ($me->state === 'removed') {
            throw new ClassroomException('removed', 'The tutor removed you from this lesson.', 403);
        }
        if ($classroom->isEnded()) {
            throw new ClassroomException('ended', 'The tutor ended this lesson.', 409, ['after' => route('classroom.after', $tutorSession)]);
        }
        if ($me->state !== 'joined') {
            throw new ClassroomException('not_joined', 'Join the lesson first.', 409);
        }
        if ($me->client_id !== null && $request->header('X-Client-Id') !== $me->client_id) {
            throw new ClassroomException('displaced', 'This lesson is open in another window or device.', 409);
        }

        if ($recheck && ! Cache::has($key = $this->admissionKey($classroom, $request))) {
            $admission = $this->access->admit($request->user(), $tutorSession, $classroom);
            if (! $admission->allowed) {
                $this->rooms->leave($classroom, $me, $admission->code === 'closed' ? 'ended' : 'left');
                throw ClassroomException::from($admission);
            }
            Cache::put($key, true, now()->addSeconds(30));
        }

        return [$classroom, $me];
    }

    private function admissionKey(Classroom $classroom, Request $request): string
    {
        return 'classroom-admitted:'.$classroom->id.':'.$request->user()->id;
    }
}
