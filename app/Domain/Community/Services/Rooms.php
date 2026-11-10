<?php

namespace App\Domain\Community\Services;

use App\Domain\Academic\Contracts\AcademicScope;
use App\Domain\Academic\Models\AcademicLevel;
use App\Domain\Academic\Models\SubjectOffering;
use App\Domain\Community\Models\Post;
use App\Domain\Community\Models\Room;
use App\Domain\Community\Models\RoomMember;
use App\Domain\Community\Support\CommunityRefusal;
use App\Domain\Safety\Audit;
use App\Domain\Safety\Support\SafeguardingDenied;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;

/**
 * Subject rooms come from the academic structure: one per curriculum offering and audience, created the first
 * time someone opens it (no invented rooms, no empty rows for every offering). A stage hub ("Grade 10, CAPS")
 * lists the subject rooms of one level.
 */
class Rooms
{
    public function __construct(
        private readonly AcademicScope $academic,
        private readonly RoomAccess $access,
    ) {}

    /** The subject room for an offering and audience, created lazily. */
    public function subjectRoom(SubjectOffering $offering, string $audience): Room
    {
        abort_unless(in_array($audience, [Room::UNDER_18, Room::ADULT], true), 404);
        abort_unless($offering->is_active, 404);

        $key = Room::subjectKey($offering->id, $audience);
        $existing = Room::query()->where('subject_key', $key)->first();
        if ($existing !== null) {
            return $existing;
        }

        try {
            return Room::create([
                'kind' => Room::SUBJECT,
                'subject_key' => $key,
                'curriculum_subject_level_id' => $offering->id,
                'curriculum_id' => $offering->curriculum_id,
                'academic_level_id' => $offering->academic_level_id,
                'subject_id' => $offering->subject_id,
                'audience' => $audience,
                'join_rule' => Room::OPEN,
                'status' => 'active',
            ]);
        } catch (QueryException) {
            // Two people opened the same room at once: the unique key let one in; use that one.
            return Room::query()->where('subject_key', $key)->firstOrFail();
        }
    }

    /**
     * The hub of one level for one audience: every active offering at the level, with its room when it exists.
     *
     * @return Collection<int, array{offering: SubjectOffering, room: ?Room, open_questions: int, resolved_questions: int, last_activity_at: mixed, is_profile_subject: bool}>
     */
    public function hub(AcademicLevel $level, string $audience, array $profileSubjectIds = []): Collection
    {
        $offerings = $this->academic->offeringsAtLevel($level->id);
        $rooms = Room::query()->where('kind', Room::SUBJECT)->where('audience', $audience)
            ->whereIn('curriculum_subject_level_id', $offerings->pluck('id'))->get()->keyBy('curriculum_subject_level_id');

        $counts = $this->questionCounts($rooms->pluck('id')->all());

        return $offerings->map(function (SubjectOffering $offering) use ($rooms, $counts, $profileSubjectIds) {
            $room = $rooms->get($offering->id);

            return [
                'offering' => $offering,
                'room' => $room,
                'open_questions' => $room ? ($counts[$room->id]['open'] ?? 0) : 0,
                'resolved_questions' => $room ? ($counts[$room->id]['resolved'] ?? 0) : 0,
                'last_activity_at' => $room ? ($counts[$room->id]['last'] ?? null) : null,
                'is_profile_subject' => in_array($offering->subject_id, $profileSubjectIds, true),
            ];
        })->values();
    }

    /**
     * The learner's own rooms: the subject rooms of their profile's level and subjects (all subjects at the level
     * when they chose none), plus every room they joined.
     *
     * @return array{level: ?AcademicLevel, hub: Collection, joined: Collection<int, Room>, profile: bool}
     */
    public function mine(User $user): array
    {
        $scope = $this->academic->forLearner($user);
        $level = $scope?->levelId ? AcademicLevel::query()->with(['stage', 'curriculum'])->find($scope->levelId) : null;
        $audience = $this->access->audienceFor($user);

        $hub = collect();
        if ($level !== null) {
            $hub = $this->hub($level, $audience, $scope->subjectIds);
            if ($scope->subjectIds !== []) {
                $hub = $hub->filter(fn ($row) => $row['is_profile_subject'])->values();
            }
        }

        $joined = Room::query()->with(['subject', 'level.stage', 'curriculum', 'owner'])
            ->whereIn('id', RoomMember::query()->where('user_id', $user->id)
                ->whereIn('status', [RoomMember::ACTIVE, RoomMember::PENDING, RoomMember::AWAITING_GUARDIAN, RoomMember::AWAITING_PAYMENT])
                ->select('room_id'))
            ->where('status', 'active')
            ->orderBy('kind')->orderBy('id')->get();

        return ['level' => $level, 'hub' => $hub, 'joined' => $joined, 'profile' => $scope !== null && $scope->levelId !== null];
    }

    /** Join a subject room (an open room: the gate decides). Tutors join as tutors. */
    public function joinSubjectRoom(User $user, Room $room): RoomMember
    {
        abort_if($room->isTutorRoom(), 404);
        $this->authorizeEntry($user, $room);

        $existing = RoomMember::query()->where('room_id', $room->id)->where('user_id', $user->id)->first();
        if ($existing?->status === RoomMember::REMOVED) {
            throw CommunityRefusal::because('removed', 'A moderator removed you from this room.', 403);
        }
        if ($existing?->isActive()) {
            return $existing;
        }

        $role = $this->access->isApprovedTutor($user) ? RoomMember::TUTOR : RoomMember::LEARNER;
        $member = RoomMember::updateOrCreate(
            ['room_id' => $room->id, 'user_id' => $user->id],
            ['role' => $role, 'status' => RoomMember::ACTIVE, 'joined_at' => now()],
        );

        Audit::record($user, 'community.room.joined', $room, ['role' => $role]);

        return $member;
    }

    public function leave(User $user, Room $room): void
    {
        $member = RoomMember::query()->where('room_id', $room->id)->where('user_id', $user->id)->first();
        if ($member === null || in_array($member->status, [RoomMember::LEFT, RoomMember::REMOVED], true)) {
            return;
        }

        if ($member->role === RoomMember::OWNER) {
            throw CommunityRefusal::because('owner_cannot_leave', 'You run this room. Archive it instead of leaving.', 409);
        }

        if ($member->holds_seat) {
            $this->access->releaseSeat($room);
        }

        $member->forceFill(['status' => RoomMember::LEFT, 'holds_seat' => false])->save();
        Audit::record($user, 'community.room.left', $room);
    }

    /** The gate's refusal as a 403 with its reasons. */
    public function authorizeEntry(User $user, Room $room): void
    {
        if ($this->access->isOperator($user)) {
            return;
        }

        $decision = $this->access->eligibility($user, $room);
        if ($decision->denied()) {
            throw new SafeguardingDenied($decision);
        }
    }

    /**
     * Open and resolved question counts and last activity per room (real counts of visible threads).
     *
     * @param  list<int>  $roomIds
     * @return array<int, array{open: int, resolved: int, last: mixed}>
     */
    public function questionCounts(array $roomIds): array
    {
        if ($roomIds === []) {
            return [];
        }

        $rows = Post::query()->whereIn('room_id', $roomIds)->whereNull('parent_id')->where('status', Post::VISIBLE)
            ->selectRaw("room_id, sum(case when kind = 'question' and resolved_at is null then 1 else 0 end) as open_q, sum(case when kind = 'question' and resolved_at is not null then 1 else 0 end) as resolved_q, max(last_activity_at) as last_at")
            ->groupBy('room_id')->get();

        $out = [];
        foreach ($rows as $row) {
            $out[(int) $row->room_id] = ['open' => (int) $row->open_q, 'resolved' => (int) $row->resolved_q, 'last' => $row->last_at];
        }

        return $out;
    }
}
