<?php

namespace App\Domain\Community\Services;

use App\Domain\Community\Models\Room;
use App\Domain\Community\Models\RoomMember;
use App\Domain\Money\Contracts\EntitlementCheck;
use App\Domain\Safety\Audit;
use App\Domain\Safety\Contracts\SafeguardingGate;
use App\Domain\Safety\Services\AccountStanding;
use App\Domain\Safety\Support\Decision;
use App\Models\TutorProfile;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Who may see, enter and take part in a room. Every answer is server side and goes through safety's
 * SafeguardingGate (join_room) with the room as an AgeGatedSpace: a room for learners under 18 is closed to
 * adults who are not screened, and operators (moderators) are let in by the gate's own rule.
 */
class RoomAccess
{
    public function __construct(
        private readonly SafeguardingGate $gate,
        private readonly AccountStanding $standing,
        private readonly EntitlementCheck $entitlements,
    ) {}

    /** The audience a person belongs to: under_18 for a minor, adult otherwise (unknown age follows safety's policy). */
    public function audienceFor(User $user): string
    {
        return $this->standing->isMinor($user) ? Room::UNDER_18 : Room::ADULT;
    }

    public function band(User $user): string
    {
        return $this->standing->band($user)->value;
    }

    public function isOperator(User $user): bool
    {
        return $this->standing->isOperator($user);
    }

    /** The gate's answer for entering this room (reasons on denial, requirements such as a guardian's approval). */
    public function eligibility(User $user, Room $room): Decision
    {
        return $this->gate->allows($user, 'join_room', $room);
    }

    /** May this person read the room? Subject rooms: anyone the gate lets in. Tutor rooms: members, the owner, operators. */
    public function canRead(?User $user, Room $room): bool
    {
        if ($user === null || ! $room->isActive() && ! $this->canManage($user, $room)) {
            return false;
        }

        if ($this->isOperator($user)) {
            return true;
        }

        if ($room->isTutorRoom()) {
            return $room->owner_id === $user->id || $this->activeMembership($user, $room) !== null;
        }

        return ! $this->eligibility($user, $room)->denied();
    }

    /** May this person see that a tutor room exists (its title, price, rules) to decide to enrol? */
    public function canSeeListing(User $user, Room $room): bool
    {
        if ($this->canRead($user, $room)) {
            return true;
        }

        return $room->isActive() && ! $this->eligibility($user, $room)->denied();
    }

    /** May this person post in the room? Same as reading, plus not being removed, plus a current gate answer. */
    public function canParticipate(User $user, Room $room): bool
    {
        if (! $room->isActive() || ! $this->canRead($user, $room)) {
            return false;
        }

        $member = $this->membership($user, $room);
        if ($member?->status === RoomMember::REMOVED) {
            return false;
        }

        return $this->isOperator($user) || ! $this->gate->allows($user, 'join_room', $room)->denied();
    }

    public function canManage(User $user, Room $room): bool
    {
        return $this->isOperator($user) || ($room->isTutorRoom() && $room->owner_id === $user->id);
    }

    /** A tutor in this room: the owner of a tutor room, or an approved tutor who joined a subject room as a tutor. */
    public function isTutorIn(User $user, Room $room): bool
    {
        if ($room->isTutorRoom() && $room->owner_id === $user->id) {
            return true;
        }

        return $this->activeMembership($user, $room)?->role === RoomMember::TUTOR;
    }

    public function isApprovedTutor(User $user): bool
    {
        return (bool) TutorProfile::query()->where('user_id', $user->id)->first()?->isApproved();
    }

    public function membership(User $user, Room $room): ?RoomMember
    {
        $member = RoomMember::query()->where('room_id', $room->id)->where('user_id', $user->id)->first();

        if ($member?->status === RoomMember::AWAITING_PAYMENT) {
            $member = $this->activateIfPaid($member, $room) ?? $member;
        }

        return $member;
    }

    public function activeMembership(User $user, Room $room): ?RoomMember
    {
        $member = $this->membership($user, $room);

        return $member?->isActive() ? $member : null;
    }

    /**
     * A paid place becomes active once money holds an entitlement for it (re-checked on read, and on OrderPaid).
     * The seat is taken with a conditional update, so two payments for the last seat cannot both get in.
     */
    public function activateIfPaid(RoomMember $member, Room $room): ?RoomMember
    {
        if ($member->status !== RoomMember::AWAITING_PAYMENT || ! $this->entitlements->owns($member->user_id, 'room', $room->id)) {
            return null;
        }

        return DB::transaction(function () use ($member, $room) {
            $fresh = RoomMember::query()->lockForUpdate()->find($member->id);
            if ($fresh === null || $fresh->status !== RoomMember::AWAITING_PAYMENT) {
                return $fresh;
            }

            if (! $this->takeSeat($room)) {
                // Paid after the last seat went: the place cannot be given. Finance must refund (recorded).
                Audit::record(null, 'community.room.paid_but_full', $room, ['user_id' => $fresh->user_id]);
                $fresh->forceFill(['status' => RoomMember::PENDING])->save();

                return $fresh;
            }

            $fresh->forceFill(['status' => RoomMember::ACTIVE, 'holds_seat' => true, 'joined_at' => now()])->save();

            return $fresh;
        });
    }

    /** Take one seat when the room has a capacity; true when a seat was taken or none is counted. */
    public function takeSeat(Room $room): bool
    {
        if ($room->capacity === null) {
            Room::query()->whereKey($room->id)->increment('seats_taken');

            return true;
        }

        return Room::query()->whereKey($room->id)->whereColumn('seats_taken', '<', 'capacity')->increment('seats_taken') === 1;
    }

    public function releaseSeat(Room $room): void
    {
        Room::query()->whereKey($room->id)->where('seats_taken', '>', 0)->decrement('seats_taken');
    }
}
