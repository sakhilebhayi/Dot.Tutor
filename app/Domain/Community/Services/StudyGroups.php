<?php

namespace App\Domain\Community\Services;

use App\Domain\Community\Models\GroupMember;
use App\Domain\Community\Models\GroupResource;
use App\Domain\Community\Models\GroupTask;
use App\Domain\Community\Models\RevisionItem;
use App\Domain\Community\Models\RevisionTick;
use App\Domain\Community\Models\Room;
use App\Domain\Community\Models\SessionAttendance;
use App\Domain\Community\Models\StudyGroup;
use App\Domain\Community\Models\StudySession;
use App\Domain\Community\Models\TaskAssignee;
use App\Domain\Community\Support\CommunityRefusal;
use App\Domain\Learning\Models\LibraryResource;
use App\Domain\Learning\Services\LibraryService;
use App\Domain\Safety\Audit;
use App\Domain\Safety\Services\ContactRedactor;
use App\Domain\Safety\Support\SafeguardingDenied;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

/**
 * Study groups inside a room: create (topic, exam focus, schedule note, size cap), join rules (open, approval,
 * invite code), sessions with calendar data, shared library resources, a collaborative revision list and
 * lightweight group tasks (due date, who did what). A group lives inside its room, so the room's age gate applies
 * to everyone in it.
 */
class StudyGroups
{
    public function __construct(
        private readonly RoomAccess $access,
        private readonly Rooms $rooms,
        private readonly ContactRedactor $redactor,
    ) {}

    /** @param array{name: string, topic?: ?string, exam_focus?: ?string, schedule_note?: ?string, size_cap: int, join_rule: string} $data */
    public function create(User $user, Room $room, array $data): StudyGroup
    {
        $this->mustParticipate($user, $room);

        $key = 'community-groups:'.$user->id;
        if (RateLimiter::tooManyAttempts($key, (int) config('community.groups_per_day'))) {
            throw CommunityRefusal::because('too_many', 'You have started several groups today. Try again tomorrow.', 429);
        }
        RateLimiter::hit($key, 86400);

        return DB::transaction(function () use ($user, $room, $data) {
            if (! $room->isTutorRoom() && $this->access->activeMembership($user, $room) === null && ! $this->access->isOperator($user)) {
                $this->rooms->joinSubjectRoom($user, $room);
            }

            $group = StudyGroup::create([
                'room_id' => $room->id,
                'owner_id' => $user->id,
                'name' => $this->clean($room, $user, $data['name']),
                'topic' => isset($data['topic']) ? $this->clean($room, $user, $data['topic']) : null,
                'exam_focus' => isset($data['exam_focus']) ? $this->clean($room, $user, $data['exam_focus']) : null,
                'schedule_note' => isset($data['schedule_note']) ? $this->clean($room, $user, $data['schedule_note']) : null,
                'size_cap' => $data['size_cap'],
                'join_rule' => $data['join_rule'],
                'invite_code' => $data['join_rule'] === Room::INVITE ? Str::upper(Str::random(10)) : null,
                'status' => 'active',
            ]);
            GroupMember::create(['group_id' => $group->id, 'user_id' => $user->id, 'role' => 'owner', 'status' => GroupMember::ACTIVE, 'joined_at' => now()]);
            $group->forceFill(['members_count' => 1])->save();
            Audit::record($user, 'community.group.created', $group, ['room_id' => $room->id]);

            return $group;
        });
    }

    /** @return array{status: string, member: GroupMember} */
    public function join(User $user, StudyGroup $group, ?string $code): array
    {
        $room = $group->room;
        $this->mustParticipate($user, $room);
        if ($group->status !== 'active') {
            throw CommunityRefusal::because('group_closed', 'This group has closed.', 409);
        }

        $member = GroupMember::query()->where('group_id', $group->id)->where('user_id', $user->id)->first();
        if ($member?->status === GroupMember::ACTIVE) {
            return ['status' => 'active', 'member' => $member];
        }
        if ($member?->status === GroupMember::REMOVED) {
            throw CommunityRefusal::because('removed', 'The organiser removed you from this group.', 403);
        }
        if ($group->join_rule === Room::INVITE && ! hash_equals((string) $group->invite_code, (string) $code)) {
            throw CommunityRefusal::because('invite_required', 'This group is by invitation. Ask the organiser for the code.', 403);
        }

        if ($group->join_rule === Room::APPROVAL) {
            $member = GroupMember::updateOrCreate(['group_id' => $group->id, 'user_id' => $user->id], ['role' => 'member', 'status' => GroupMember::PENDING]);

            return ['status' => 'pending', 'member' => $member];
        }

        return ['status' => 'active', 'member' => $this->admit($group, $user)];
    }

    public function decide(User $owner, StudyGroup $group, GroupMember $member, bool $accept): GroupMember
    {
        $this->mustOrganise($owner, $group);
        abort_unless($member->group_id === $group->id, 404);
        if ($member->status !== GroupMember::PENDING) {
            throw CommunityRefusal::because('not_pending', 'There is no request to decide.', 409);
        }

        if (! $accept) {
            $member->forceFill(['status' => GroupMember::REMOVED])->save();

            return $member;
        }

        $this->mustParticipate($member->user, $group->room);

        return $this->admit($group, $member->user);
    }

    public function leave(User $user, StudyGroup $group): void
    {
        $member = GroupMember::query()->where('group_id', $group->id)->where('user_id', $user->id)->where('status', GroupMember::ACTIVE)->first();
        if ($member === null) {
            return;
        }
        if ($member->role === 'owner') {
            throw CommunityRefusal::because('owner_cannot_leave', 'You organise this group. Close it instead of leaving.', 409);
        }
        DB::transaction(function () use ($member, $group) {
            $member->forceFill(['status' => GroupMember::LEFT])->save();
            StudyGroup::query()->whereKey($group->id)->where('members_count', '>', 0)->decrement('members_count');
        });
    }

    public function removeMember(User $owner, StudyGroup $group, GroupMember $member): void
    {
        $this->mustOrganise($owner, $group);
        abort_unless($member->group_id === $group->id && $member->role !== 'owner', 404);
        DB::transaction(function () use ($member, $group) {
            if ($member->status === GroupMember::ACTIVE) {
                StudyGroup::query()->whereKey($group->id)->where('members_count', '>', 0)->decrement('members_count');
            }
            $member->forceFill(['status' => GroupMember::REMOVED])->save();
        });
        Audit::record($owner, 'community.group.member_removed', $group, ['user_id' => $member->user_id]);
    }

    public function close(User $actor, StudyGroup $group): void
    {
        $this->mustOrganise($actor, $group);
        $group->forceFill(['status' => 'closed'])->save();
        Audit::record($actor, 'community.group.closed', $group);
    }

    /** @param array{title: string, starts_at: string, timezone: string, duration_minutes: int, where_note?: ?string} $data */
    public function schedule(User $actor, Room $room, ?StudyGroup $group, array $data): StudySession
    {
        if ($group !== null) {
            $this->mustOrganise($actor, $group);
        } elseif (! $room->isTutorRoom() || ! $this->access->canManage($actor, $room)) {
            throw CommunityRefusal::because('not_allowed', 'Only the tutor who runs this room schedules its workshops.', 403);
        }

        $start = CarbonImmutable::parse($data['starts_at'], $data['timezone'])->utc();
        if ($start->isPast()) {
            throw CommunityRefusal::because('in_the_past', 'Choose a time in the future.', 422);
        }

        return StudySession::create([
            'room_id' => $group === null ? $room->id : null,
            'group_id' => $group?->id,
            'created_by_id' => $actor->id,
            'title' => $this->clean($room, $actor, $data['title']),
            'starts_at' => $start,
            'duration_minutes' => $data['duration_minutes'],
            'timezone' => $data['timezone'],
            'where_note' => isset($data['where_note']) ? $this->clean($room, $actor, $data['where_note']) : null,
            'uid' => Str::uuid()->toString(),
        ]);
    }

    public function cancelSession(User $actor, StudySession $session): void
    {
        $this->mustOrganiseSession($actor, $session);
        $session->forceFill(['cancelled_at' => now()])->save();
    }

    /** The organiser records who attended, after the session started. @param list<int> $userIds */
    public function markAttendance(User $actor, StudySession $session, array $userIds): int
    {
        $this->mustOrganiseSession($actor, $session);
        if (! $session->hasStarted() || $session->cancelled_at !== null) {
            throw CommunityRefusal::because('not_started', 'Attendance is recorded once the session has started.', 409);
        }

        $allowed = $session->group_id !== null
            ? GroupMember::query()->where('group_id', $session->group_id)->where('status', GroupMember::ACTIVE)->pluck('user_id')
            : $session->room->members()->where('status', 'active')->pluck('user_id');
        $ids = array_values(array_intersect(array_map('intval', $userIds), $allowed->map(fn ($id) => (int) $id)->all()));

        DB::transaction(function () use ($session, $ids, $actor) {
            SessionAttendance::query()->where('session_id', $session->id)->whereNotIn('user_id', $ids)->delete();
            foreach ($ids as $id) {
                SessionAttendance::firstOrCreate(['session_id' => $session->id, 'user_id' => $id], ['marked_by_id' => $actor->id]);
            }
        });

        return count($ids);
    }

    public function shareResource(User $user, StudyGroup $group, int $resourceId, ?string $note): GroupResource
    {
        $this->mustBeMember($user, $group);
        $resource = LibraryResource::query()->find($resourceId);
        if ($resource === null || $resource->status->value !== 'published' || ! app(LibraryService::class)->canSee($user, $resource)) {
            throw CommunityRefusal::because('resource_not_available', 'Share a published item from the library.', 422);
        }

        return GroupResource::firstOrCreate(
            ['group_id' => $group->id, 'library_resource_id' => $resource->id],
            ['added_by_id' => $user->id, 'note' => $note !== null ? $this->clean($group->room, $user, $note) : null],
        );
    }

    public function addRevisionItem(User $user, StudyGroup $group, string $title, ?int $topicId): RevisionItem
    {
        $this->mustBeMember($user, $group);

        return RevisionItem::create(['group_id' => $group->id, 'added_by_id' => $user->id, 'title' => $this->clean($group->room, $user, $title), 'topic_id' => $topicId]);
    }

    public function tick(User $user, RevisionItem $item, bool $done): void
    {
        $this->mustBeMember($user, StudyGroup::query()->findOrFail($item->group_id));
        $done
            ? RevisionTick::firstOrCreate(['item_id' => $item->id, 'user_id' => $user->id])
            : RevisionTick::query()->where('item_id', $item->id)->where('user_id', $user->id)->delete();
    }

    /** @param list<int> $assigneeIds */
    public function addTask(User $user, StudyGroup $group, string $title, ?string $details, ?string $dueOn, array $assigneeIds): GroupTask
    {
        $this->mustBeMember($user, $group);
        $members = GroupMember::query()->where('group_id', $group->id)->where('status', GroupMember::ACTIVE)->pluck('user_id')->map(fn ($id) => (int) $id)->all();
        $assignees = array_values(array_intersect(array_map('intval', $assigneeIds), $members));

        return DB::transaction(function () use ($user, $group, $title, $details, $dueOn, $assignees) {
            $task = GroupTask::create([
                'group_id' => $group->id,
                'created_by_id' => $user->id,
                'title' => $this->clean($group->room, $user, $title),
                'details' => $details !== null ? $this->clean($group->room, $user, $details) : null,
                'due_on' => $dueOn,
            ]);
            foreach ($assignees as $id) {
                TaskAssignee::create(['task_id' => $task->id, 'user_id' => $id]);
            }

            return $task;
        });
    }

    /** An assignee marks their part done (or not done), with an optional note of what they did. */
    public function markTask(User $user, GroupTask $task, bool $done, ?string $note): TaskAssignee
    {
        $group = StudyGroup::query()->findOrFail($task->group_id);
        $this->mustBeMember($user, $group);
        $assignee = TaskAssignee::query()->where('task_id', $task->id)->where('user_id', $user->id)->first();
        if ($assignee === null) {
            $assignee = TaskAssignee::create(['task_id' => $task->id, 'user_id' => $user->id]);
        }
        $assignee->forceFill([
            'done_at' => $done ? now() : null,
            'done_note' => $done && $note !== null ? $this->clean($group->room, $user, $note) : null,
        ])->save();

        return $assignee;
    }

    public function isMember(User $user, StudyGroup $group): bool
    {
        return GroupMember::query()->where('group_id', $group->id)->where('user_id', $user->id)->where('status', GroupMember::ACTIVE)->exists();
    }

    public function isOrganiser(User $user, StudyGroup $group): bool
    {
        return $group->owner_id === $user->id || $this->access->isOperator($user);
    }

    private function admit(StudyGroup $group, User $user): GroupMember
    {
        return DB::transaction(function () use ($group, $user) {
            $taken = StudyGroup::query()->whereKey($group->id)->whereColumn('members_count', '<', 'size_cap')->increment('members_count');
            if ($taken !== 1) {
                throw CommunityRefusal::because('group_full', 'This group is full.', 409);
            }

            return GroupMember::updateOrCreate(['group_id' => $group->id, 'user_id' => $user->id], ['role' => 'member', 'status' => GroupMember::ACTIVE, 'joined_at' => now()]);
        });
    }

    private function mustParticipate(User $user, Room $room): void
    {
        if (! $this->access->canParticipate($user, $room)) {
            $decision = $this->access->eligibility($user, $room);
            if ($decision->denied()) {
                throw new SafeguardingDenied($decision);
            }
            throw CommunityRefusal::because('not_a_member', 'Join this room first.', 403);
        }
    }

    private function mustBeMember(User $user, StudyGroup $group): void
    {
        $this->mustParticipate($user, $group->room);
        if (! $this->isMember($user, $group)) {
            throw CommunityRefusal::because('not_a_group_member', 'Join the group first.', 403);
        }
    }

    private function mustOrganise(User $user, StudyGroup $group): void
    {
        if (! $this->isOrganiser($user, $group)) {
            throw CommunityRefusal::because('not_organiser', 'Only the person who organises this group can do that.', 403);
        }
    }

    private function mustOrganiseSession(User $actor, StudySession $session): void
    {
        $session->group_id !== null
            ? $this->mustOrganise($actor, $session->group)
            : ($this->access->canManage($actor, $session->room) ?: throw CommunityRefusal::because('not_allowed', 'Only the tutor who runs this room can do that.', 403));
    }

    /** Short texts in a room for minors (or by a minor) have contact details hidden, like posts. */
    private function clean(Room $room, User $author, string $text): string
    {
        $text = trim($text);
        if (! $room->isForMinors() && $this->access->audienceFor($author) === Room::ADULT) {
            return $text;
        }

        return $this->redactor->redact($text)['text'];
    }
}
