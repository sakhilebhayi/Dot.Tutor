<?php

namespace App\Domain\Classroom\Services;

use App\Domain\Classroom\Events\ClassroomEnded;
use App\Domain\Classroom\Events\ClassroomOpened;
use App\Domain\Classroom\Models\AttendanceSpan;
use App\Domain\Classroom\Models\Classroom;
use App\Domain\Classroom\Models\ClassroomAttendee;
use App\Domain\Classroom\Models\ClassroomSignal;
use App\Domain\Classroom\Support\Admission;
use App\Domain\Classroom\Support\ClassroomException;
use App\Domain\Marketplace\Exceptions\MarketplaceException;
use App\Domain\Marketplace\Services\BookingFlow;
use App\Domain\Safety\Contracts\AuditLog;
use App\Enums\SessionStatus;
use App\Models\TutorSession;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * The classroom's memory (adapted from Dot.Engage's MeetingService). Browsers connect to each other directly over
 * WebRTC; the server only knows who is in the room right now, passes the short-lived set-up messages between them,
 * and keeps attendance from real joins and polls.
 */
class ClassroomService
{
    /** A joined person unseen this long is taken out of the room and their attendance span closed. */
    public const LOST_DROP_SECONDS = 90;

    public function __construct(
        private readonly AuditLog $audit,
        private readonly BookingFlow $bookings,
        private readonly IceConfig $ice,
    ) {}

    /** The classroom of a lesson, created on first use. */
    public function for(TutorSession $session): Classroom
    {
        $classroom = Classroom::query()->where('tutor_session_id', $session->id)->first();
        if ($classroom === null) {
            try {
                $classroom = Classroom::query()->create(['tutor_session_id' => $session->id, 'room_key' => Str::random(32), 'status' => 'scheduled']);
            } catch (UniqueConstraintViolationException) {
                $classroom = Classroom::query()->where('tutor_session_id', $session->id)->firstOrFail();
            }
        }
        $classroom->setRelation('session', $session);

        return $classroom;
    }

    // ── Entering and leaving ────────────────────────────────────────────────

    /**
     * Join the room (after the pre-join check). Refused when the admission says no or the mesh is full. A second
     * window of the same person takes over (the first is told on its next poll).
     *
     * @return array{attendee: ClassroomAttendee, ice: array<string, mixed>}
     */
    public function join(Classroom $classroom, User $user, Admission $admission, string $clientId): array
    {
        if (! $admission->allowed) {
            throw ClassroomException::from($admission);
        }
        $session = $classroom->session;

        $result = DB::transaction(function () use ($classroom, $user, $admission, $clientId) {
            $locked = Classroom::query()->whereKey($classroom->id)->lockForUpdate()->first();
            $present = ClassroomAttendee::query()->where('classroom_id', $locked->id)->where('state', 'joined')
                ->where('user_id', '!=', $user->id)
                ->where('last_seen_at', '>', now()->subSeconds((int) config('classroom.presence_seconds')))->count();
            $max = (int) config('classroom.max_in_room');
            if ($present >= $max) {
                throw new ClassroomException('room_full', "The classroom holds {$max} people at once and is full. Use the tutor's meeting link if one is set, or try again when someone leaves.", 409);
            }

            $attendee = ClassroomAttendee::query()->firstOrNew(['classroom_id' => $locked->id, 'user_id' => $user->id]);
            $attendee->forceFill([
                'role' => $admission->role,
                'state' => 'joined',
                'client_id' => $clientId,
                'last_seen_at' => now(),
                'mic_on' => false, 'cam_on' => false, 'sharing' => false, 'quality' => null,
                'join_count' => (int) $attendee->join_count + 1,
            ])->save();

            // One open span per person: a rejoin (reload, second window) closes the previous one first.
            AttendanceSpan::query()->where('classroom_id', $locked->id)->where('user_id', $user->id)->whereNull('left_at')
                ->update(['left_at' => now(), 'left_reason' => 'left', 'updated_at' => now()]);
            AttendanceSpan::query()->create([
                'classroom_id' => $locked->id, 'user_id' => $user->id, 'role' => $admission->role,
                'joined_at' => now(), 'last_seen_at' => now(),
            ]);

            $first = $locked->status !== 'live';
            if ($first) {
                $locked->forceFill(['status' => 'live', 'started_at' => $locked->started_at ?? now(), 'ended_at' => null, 'ended_reason' => null])->save();
            }

            return [$attendee, $first, $locked];
        });
        [$row, $first, $locked] = $result;
        $classroom->setRawAttributes($locked->getAttributes(), true);

        // The tutor walking in starts the lesson in the marketplace (its own rules: from 15 minutes before the start).
        if ($admission->role === 'tutor' && $session->statusEnum() === SessionStatus::Confirmed) {
            try {
                $this->bookings->start($session, $user);
            } catch (MarketplaceException $e) {
                Log::info('classroom: lesson not started in the marketplace', ['session' => $session->id, 'error' => $e->error]);
            }
        }

        $this->audit->record($user, 'classroom.joined', $classroom, ['tutor_session_id' => $session->id, 'role' => $admission->role]);
        if ($first) {
            ClassroomOpened::dispatch($classroom, ['by' => $user->id]);
        }

        return ['attendee' => $row, 'ice' => $this->ice->forUser($user->id)];
    }

    public function leave(Classroom $classroom, ClassroomAttendee $attendee, string $reason = 'left'): void
    {
        DB::transaction(function () use ($classroom, $attendee, $reason) {
            if ($attendee->state === 'joined') {
                $attendee->forceFill(['state' => $reason === 'removed' ? 'removed' : 'left', 'mic_on' => false, 'cam_on' => false, 'sharing' => false])->save();
            }
            $this->closeSpans($classroom->id, $attendee->user_id, $reason);
        });
    }

    /** The tutor ends the lesson for everyone. Within the room's open time the tutor may come back in (reopens it). */
    public function end(Classroom $classroom, ?User $by, string $reason = 'tutor_ended'): void
    {
        if ($classroom->isEnded()) {
            return;
        }
        DB::transaction(function () use ($classroom, $reason) {
            $classroom->forceFill(['status' => 'ended', 'ended_at' => now(), 'ended_reason' => $reason])->save();
            ClassroomAttendee::query()->where('classroom_id', $classroom->id)->where('state', 'joined')
                ->update(['state' => 'left', 'mic_on' => false, 'cam_on' => false, 'sharing' => false, 'updated_at' => now()]);
            AttendanceSpan::query()->where('classroom_id', $classroom->id)->whereNull('left_at')
                ->update(['left_at' => now(), 'left_reason' => 'ended', 'updated_at' => now()]);
            ClassroomSignal::query()->where('classroom_id', $classroom->id)->delete();
        });
        $this->audit->record($by, 'classroom.ended', $classroom, ['tutor_session_id' => $classroom->tutor_session_id, 'reason' => $reason]);
        ClassroomEnded::dispatch($classroom, ['reason' => $reason]);
    }

    // ── What the browser polls ──────────────────────────────────────────────

    /** @param array{mic?: mixed, cam?: mixed, sharing?: mixed, quality?: mixed} $state */
    public function touch(ClassroomAttendee $attendee, array $state = []): void
    {
        $attendee->forceFill(array_filter([
            'last_seen_at' => now(),
            'mic_on' => array_key_exists('mic', $state) ? (bool) $state['mic'] : null,
            'cam_on' => array_key_exists('cam', $state) ? (bool) $state['cam'] : null,
            'sharing' => array_key_exists('sharing', $state) ? (bool) $state['sharing'] : null,
            'quality' => isset($state['quality']) && in_array($state['quality'], ['good', 'fair', 'poor'], true) ? $state['quality'] : null,
        ], fn ($v) => $v !== null))->save();

        AttendanceSpan::query()->where('classroom_id', $attendee->classroom_id)->where('user_id', $attendee->user_id)->whereNull('left_at')
            ->update(['last_seen_at' => now(), 'updated_at' => now()]);
    }

    /**
     * The roster and the signals addressed to me since $since. Also drops people whose browser stopped answering and
     * deletes expired signals, so the room tidies itself without a daemon.
     *
     * @return array<string, mixed>
     */
    public function snapshot(Classroom $classroom, ClassroomAttendee $me, int $since): array
    {
        $this->dropLost($classroom);
        $this->purgeSignals($classroom->id);
        $classroom->refresh();
        $me->refresh();

        $roster = ClassroomAttendee::query()->with('user:id,name')->where('classroom_id', $classroom->id)->where('state', 'joined')
            ->orderBy('id')->get()
            ->map(fn (ClassroomAttendee $a) => [
                'id' => $a->id,
                'name' => $a->user?->name ?? 'Someone',
                'role' => $a->role,
                'state' => $a->isPresent() ? 'joined' : 'lost',
                'mic' => $a->mic_on, 'cam' => $a->cam_on, 'sharing' => $a->sharing, 'quality' => $a->quality,
                'me' => $a->id === $me->id,
            ])->values();

        $signals = ClassroomSignal::query()->where('classroom_id', $classroom->id)->where('id', '>', $since)
            ->where(fn ($q) => $q->where('to_attendee_id', $me->id)->orWhere(fn ($q) => $q->whereNull('to_attendee_id')->where('from_attendee_id', '!=', $me->id)))
            ->orderBy('id')->limit(200)->get(['id', 'from_attendee_id', 'type', 'payload']);

        return [
            'classroom' => ['status' => $classroom->status, 'ended_reason' => $classroom->ended_reason, 'board_pages' => $classroom->board_pages],
            'me' => ['id' => $me->id, 'state' => $me->state, 'role' => $me->role],
            'roster' => $roster,
            'signals' => $signals->map(fn (ClassroomSignal $s) => ['id' => $s->id, 'from' => $s->from_attendee_id, 'type' => $s->type, 'payload' => $s->payload])->values(),
            'last_id' => (int) ($signals->last()->id ?? $since),
        ];
    }

    public function signal(Classroom $classroom, ClassroomAttendee $from, ?int $to, string $type, string $payload): ClassroomSignal
    {
        if (! in_array($type, ['offer', 'answer', 'candidate'], true)) {
            throw new ClassroomException('unknown_signal', 'Unknown signal.');
        }
        if (strlen($payload) > 24_000) {
            throw new ClassroomException('signal_too_large', 'That signal is too large.');
        }
        if ($from->state !== 'joined') {
            throw new ClassroomException('not_joined', 'Join the lesson first.', 409);
        }
        if ($to !== null && ! ClassroomAttendee::query()->where('classroom_id', $classroom->id)->where('state', 'joined')->whereKey($to)->exists()) {
            throw new ClassroomException('not_in_room', 'That person is not in the lesson.', 409);
        }

        return ClassroomSignal::query()->create([
            'classroom_id' => $classroom->id, 'from_attendee_id' => $from->id, 'to_attendee_id' => $to,
            'type' => $type, 'payload' => $payload, 'created_at' => now(),
        ]);
    }

    // ── The tutor's controls ────────────────────────────────────────────────

    /** mute (asks that person's browser to turn its microphone off) or remove (out of this lesson for good). */
    public function tutorAction(Classroom $classroom, ClassroomAttendee $tutor, string $action, ClassroomAttendee $target): void
    {
        if ($tutor->role !== 'tutor') {
            throw new ClassroomException('tutor_only', 'Only the tutor can do that.', 403);
        }
        if ($target->classroom_id !== $classroom->id || $target->id === $tutor->id) {
            throw new ClassroomException('bad_target', 'Pick a person in this lesson.');
        }

        match ($action) {
            'mute' => $this->command($classroom, $tutor, $target, 'mute'),
            'remove' => $this->remove($classroom, $tutor, $target),
            default => throw new ClassroomException('unknown_action', 'Unknown action.'),
        };
    }

    private function remove(Classroom $classroom, ClassroomAttendee $tutor, ClassroomAttendee $target): void
    {
        $this->command($classroom, $tutor, $target, 'removed');
        $target->forceFill(['state' => 'removed', 'mic_on' => false, 'cam_on' => false, 'sharing' => false])->save();
        $this->closeSpans($classroom->id, $target->user_id, 'removed');
        $this->audit->record($tutor->user, 'classroom.removed', $classroom, ['tutor_session_id' => $classroom->tutor_session_id, 'user_id' => $target->user_id]);
    }

    private function command(Classroom $classroom, ClassroomAttendee $from, ClassroomAttendee $to, string $command): void
    {
        ClassroomSignal::query()->create([
            'classroom_id' => $classroom->id, 'from_attendee_id' => $from->id, 'to_attendee_id' => $to->id,
            'type' => 'command', 'payload' => json_encode(['command' => $command]), 'created_at' => now(),
        ]);
    }

    // ── Housekeeping ────────────────────────────────────────────────────────

    public function dropLost(Classroom $classroom): int
    {
        $lost = ClassroomAttendee::query()->where('classroom_id', $classroom->id)->where('state', 'joined')
            ->where('last_seen_at', '<', now()->subSeconds(self::LOST_DROP_SECONDS))->get();

        foreach ($lost as $attendee) {
            $attendee->forceFill(['state' => 'left', 'mic_on' => false, 'cam_on' => false, 'sharing' => false])->save();
            $this->closeSpans($classroom->id, $attendee->user_id, 'lost');
        }

        return $lost->count();
    }

    public function purgeSignals(?int $classroomId = null): int
    {
        return ClassroomSignal::query()
            ->when($classroomId, fn ($q) => $q->where('classroom_id', $classroomId))
            ->where('created_at', '<', now()->subSeconds((int) config('classroom.signal_ttl_seconds')))
            ->delete();
    }

    /**
     * Run by classroom:sweep every minute: drop lost people, delete expired signals, close rooms past their grace time.
     *
     * @return array{lost: int, signals: int, closed: int}
     */
    public function sweep(ClassroomAccess $access): array
    {
        $lost = 0;
        $closed = 0;
        foreach (Classroom::query()->with('session')->whereIn('status', ['live', 'scheduled'])->where('updated_at', '>', now()->subDays(2))->get() as $classroom) {
            $lost += $this->dropLost($classroom);
            if ($classroom->session && now()->gt($access->closesAt($classroom->session)) && $classroom->status === 'live') {
                $this->end($classroom, null, 'closed');
                $closed++;
            }
        }

        return ['lost' => $lost, 'signals' => $this->purgeSignals(), 'closed' => $closed];
    }

    private function closeSpans(int $classroomId, int $userId, string $reason): void
    {
        AttendanceSpan::query()->where('classroom_id', $classroomId)->where('user_id', $userId)->whereNull('left_at')
            ->update(['left_at' => now(), 'left_reason' => $reason, 'updated_at' => now()]);
    }
}
