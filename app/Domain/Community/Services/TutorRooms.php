<?php

namespace App\Domain\Community\Services;

use App\Domain\Academic\Models\SubjectOffering;
use App\Domain\Community\Events\JoinRequested;
use App\Domain\Community\Models\Room;
use App\Domain\Community\Models\RoomMember;
use App\Domain\Community\Support\CommunityRefusal;
use App\Domain\Money\CheckoutStart;
use App\Domain\Money\Contracts\Checkout;
use App\Domain\Money\Exceptions\CheckoutRefused;
use App\Domain\Money\Models\Order;
use App\Domain\Safety\Audit;
use App\Domain\Safety\Contracts\GuardianApprovals;
use App\Domain\Safety\Contracts\SafeguardingGate;
use App\Domain\Safety\Models\GuardianApproval;
use App\Domain\Safety\Support\SafeguardingDenied;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Tutor-led rooms and workshops: free or paid (through money's Checkout, purchasable type "room"), with an
 * enrolment rule (open, approval, invite code) and a capacity. A room for learners under 18 can only be run by a
 * tutor the safeguarding gate lets in (screened).
 */
class TutorRooms
{
    public function __construct(
        private readonly RoomAccess $access,
        private readonly SafeguardingGate $gate,
        private readonly GuardianApprovals $approvals,
        private readonly Checkout $checkout,
    ) {}

    /** @param array{curriculum_subject_level_id: int, audience: string, title: string, description?: ?string, language?: ?string, join_rule: string, capacity?: ?int, price_minor?: int} $data */
    public function create(User $tutor, array $data): Room
    {
        if (! $this->access->isApprovedTutor($tutor)) {
            throw CommunityRefusal::because('not_an_approved_tutor', 'Only approved tutors run their own rooms. Finish your tutor profile first.', 403);
        }

        $offering = SubjectOffering::query()->active()->find($data['curriculum_subject_level_id']);
        if ($offering === null) {
            throw CommunityRefusal::because('unknown_offering', 'Choose a subject and level from the list.', 422);
        }

        $price = (int) ($data['price_minor'] ?? 0);
        $publish = $this->gate->allows($tutor, 'publish', null, ['audience' => 'public', 'commercial' => $price > 0]);
        if ($publish->denied()) {
            throw new SafeguardingDenied($publish);
        }

        if ($price > 0 && $data['join_rule'] === Room::APPROVAL) {
            throw CommunityRefusal::because('paid_needs_open_or_invite', 'A paid room is open or by invitation; learners pay when they join.', 422);
        }

        $room = new Room([
            'kind' => Room::TUTOR,
            'curriculum_subject_level_id' => $offering->id,
            'curriculum_id' => $offering->curriculum_id,
            'academic_level_id' => $offering->academic_level_id,
            'subject_id' => $offering->subject_id,
            'audience' => $data['audience'],
            'owner_id' => $tutor->id,
            'title' => $data['title'],
            'description' => $data['description'] ?? null,
            'language' => $data['language'] ?? null,
            'join_rule' => $data['join_rule'],
            'invite_code' => $data['join_rule'] === Room::INVITE ? $this->code() : null,
            'capacity' => $data['capacity'] ?? null,
            'price_minor' => $price,
            'currency' => config('community.currency'),
            'status' => 'active',
        ]);

        // The tutor is an adult in the room: for a room of learners under 18 the gate requires their screening.
        $entry = $this->gate->allows($tutor, 'join_room', $room);
        if ($entry->denied()) {
            throw new SafeguardingDenied($entry);
        }

        return DB::transaction(function () use ($room, $tutor) {
            $room->save();
            RoomMember::create(['room_id' => $room->id, 'user_id' => $tutor->id, 'role' => RoomMember::OWNER, 'status' => RoomMember::ACTIVE, 'joined_at' => now()]);
            Audit::record($tutor, 'community.tutor_room.created', $room, ['audience' => $room->audience, 'paid' => $room->isPaid()]);

            return $room;
        });
    }

    public function archive(User $actor, Room $room): void
    {
        $this->mustManage($actor, $room);
        $room->forceFill(['status' => 'archived'])->save();
        Audit::record($actor, 'community.tutor_room.archived', $room);
    }

    public function newInviteCode(User $actor, Room $room): string
    {
        $this->mustManage($actor, $room);
        $room->forceFill(['invite_code' => $this->code(), 'join_rule' => Room::INVITE])->save();

        return $room->invite_code;
    }

    /**
     * Enrol in a tutor room. Free: join at once (open), ask (approval) or with the code (invite). Paid: a minor's
     * guardian approves first (safety), then an order is created and payment started through money.
     *
     * @return array{status: string, member: RoomMember, checkout?: CheckoutStart, message: string}
     */
    public function enrol(User $user, Room $room, ?string $code, ?string $idempotencyKey): array
    {
        abort_unless($room->isTutorRoom(), 404);
        if (! $room->isActive()) {
            throw CommunityRefusal::because('room_closed', 'This room is closed.', 409);
        }
        if ($room->owner_id === $user->id) {
            throw CommunityRefusal::because('own_room', 'You run this room.', 409);
        }

        $decision = $this->gate->allows($user, 'join_room', $room);
        if ($decision->denied()) {
            throw new SafeguardingDenied($decision);
        }

        $member = RoomMember::query()->where('room_id', $room->id)->where('user_id', $user->id)->first();
        if ($member?->status === RoomMember::REMOVED) {
            throw CommunityRefusal::because('removed', 'The tutor removed you from this room.', 403);
        }
        if ($member?->isActive()) {
            return ['status' => 'active', 'member' => $member, 'message' => 'You are already in this room.'];
        }

        if ($room->join_rule === Room::INVITE && ! hash_equals((string) $room->invite_code, (string) $code)) {
            throw CommunityRefusal::because('invite_required', 'This room is by invitation. Ask the tutor for the code.', 403);
        }

        if ($room->capacity !== null && $room->seats_taken >= $room->capacity) {
            throw CommunityRefusal::because('room_full', 'This room is full.', 409);
        }

        if ($room->isPaid()) {
            return $this->enrolPaid($user, $room, $member, $decision->requires('guardian_approval:paid_room'), $idempotencyKey);
        }

        if ($room->join_rule === Room::APPROVAL) {
            $member = $this->upsert($room, $user, RoomMember::PENDING);
            JoinRequested::dispatch(JoinRequested::ROOM, $room->id, $user->id, $member->id);

            return ['status' => 'pending', 'member' => $member, 'message' => 'Your request is with the tutor.'];
        }

        return DB::transaction(function () use ($room, $user) {
            if (! $this->access->takeSeat($room)) {
                throw CommunityRefusal::because('room_full', 'This room is full.', 409);
            }
            $member = $this->upsert($room, $user, RoomMember::ACTIVE, true);
            Audit::record($user, 'community.tutor_room.joined', $room);

            return ['status' => 'active', 'member' => $member, 'message' => 'You joined the room.'];
        });
    }

    /** The owner accepts or declines a pending request (free rooms with the approval rule). */
    public function decide(User $owner, Room $room, RoomMember $member, bool $accept): RoomMember
    {
        $this->mustManage($owner, $room);
        abort_unless($member->room_id === $room->id, 404);
        if ($member->status !== RoomMember::PENDING) {
            throw CommunityRefusal::because('not_pending', 'There is no request to decide.', 409);
        }

        if (! $accept) {
            $member->forceFill(['status' => RoomMember::REMOVED])->save();

            return $member;
        }

        // The learner must still pass the gate today (a suspension or a lapsed screening since they asked).
        $decision = $this->gate->allows($member->user, 'join_room', $room);
        if ($decision->denied()) {
            throw new SafeguardingDenied($decision);
        }

        return DB::transaction(function () use ($room, $member, $owner) {
            if (! $this->access->takeSeat($room)) {
                throw CommunityRefusal::because('room_full', 'This room is full.', 409);
            }
            $member->forceFill(['status' => RoomMember::ACTIVE, 'holds_seat' => true, 'joined_at' => now()])->save();
            Audit::record($owner, 'community.tutor_room.request_accepted', $room, ['user_id' => $member->user_id]);

            return $member;
        });
    }

    public function removeMember(User $actor, Room $room, RoomMember $member): void
    {
        $this->mustManage($actor, $room);
        abort_unless($member->room_id === $room->id && $member->role !== RoomMember::OWNER, 404);

        if ($member->holds_seat) {
            $this->access->releaseSeat($room);
        }
        $member->forceFill(['status' => RoomMember::REMOVED, 'holds_seat' => false])->save();
        Audit::record($actor, 'community.tutor_room.member_removed', $room, ['user_id' => $member->user_id]);
    }

    /** @return array{status: string, member: RoomMember, checkout?: CheckoutStart, message: string} */
    private function enrolPaid(User $user, Room $room, ?RoomMember $member, bool $needsGuardian, ?string $idempotencyKey): array
    {
        if ($member?->status === RoomMember::AWAITING_PAYMENT && ($active = $this->access->activateIfPaid($member, $room))?->isActive()) {
            return ['status' => 'active', 'member' => $active, 'message' => 'Your payment is confirmed. Welcome to the room.'];
        }

        if ($idempotencyKey === null || $idempotencyKey === '') {
            throw CommunityRefusal::because('idempotency_key_required', 'Send an Idempotency-Key header with a paid enrolment.', 400);
        }

        $order = $this->orderFor($user, $room, $idempotencyKey);

        // The guardian is asked once. With the integration bindings money holds a minor's order and asks the
        // guardian to approve that purchase (naming the room and the total). If money did not hold it (no safety
        // binding), safety is asked here for a paid-room approval and no payment starts until it is given. Money
        // re-assesses a held order on every start, so a guardian's later answer is picked up on the next try.
        if ($needsGuardian && $order->status !== Order::AWAITING_GUARDIAN && $order->guardian_status !== 'approved' && ! $this->approvals->isApproved($user, 'paid_room', $room)) {
            if ($this->approvals->status($user, 'paid_room', $room) !== GuardianApproval::PENDING) {
                $this->approvals->request($user, 'paid_room', $room, [
                    'summary' => mb_substr('Paid room: '.$room->displayName(), 0, 255),
                    'amount_minor' => $room->price_minor,
                    'currency' => $room->currency,
                ]);
            }
            $member = $this->upsert($room, $user, RoomMember::AWAITING_GUARDIAN);

            return ['status' => 'awaiting_guardian', 'member' => $member, 'message' => 'A parent or guardian must approve this paid room first. We have asked them; come back here once they have.'];
        }

        try {
            $start = $this->checkout->startPayment($order, $user, route('rooms.show', $room));
        } catch (CheckoutRefused $refused) {
            throw CommunityRefusal::because('checkout_refused', $refused->getMessage(), 409);
        }

        $member = $this->upsert($room, $user, $start->status === CheckoutStart::HELD ? RoomMember::AWAITING_GUARDIAN : RoomMember::AWAITING_PAYMENT);
        Audit::record($user, 'community.tutor_room.checkout_started', $room, ['order' => $order->uuid ?? null, 'status' => $start->status]);

        if ($start->status === CheckoutStart::HELD) {
            return ['status' => 'awaiting_guardian', 'member' => $member, 'checkout' => $start, 'message' => 'A parent or guardian must approve this paid room first. We have asked them; come back here once they have.'];
        }

        if ($start->status === CheckoutStart::SETTLED) {
            $member = $this->access->activateIfPaid($member, $room) ?? $member;
        }

        return [
            'status' => $member->isActive() ? 'active' : ($start->status === CheckoutStart::HELD ? 'awaiting_guardian' : 'awaiting_payment'),
            'member' => $member,
            'checkout' => $start,
            'message' => $start->message ?? match ($start->status) {
                CheckoutStart::REDIRECT => 'Continue to payment.',
                CheckoutStart::UNAVAILABLE => 'Online payment is not available yet. Nothing has been charged.',
                default => 'Payment started.',
            },
        ];
    }

    /** The order for this room: a new one, or the buyer's open order for it (a retry after a guardian answered). */
    private function orderFor(User $user, Room $room, string $idempotencyKey): Order
    {
        try {
            return $this->checkout->createOrder($user, [['type' => 'room', 'id' => $room->id]], $idempotencyKey);
        } catch (CheckoutRefused $refused) {
            $open = $refused->reason === 'already_in_open_order' ? Order::query()->where('uuid', $refused->context['order_id'] ?? '')->where('buyer_id', $user->id)->first() : null;
            if ($open === null) {
                throw CommunityRefusal::because('checkout_refused', $refused->getMessage(), $refused->reason === 'already_purchased' ? 409 : 422);
            }

            return $open;
        }
    }

    private function upsert(Room $room, User $user, string $status, bool $seat = false): RoomMember
    {
        return RoomMember::updateOrCreate(
            ['room_id' => $room->id, 'user_id' => $user->id],
            ['role' => RoomMember::LEARNER, 'status' => $status, 'holds_seat' => $seat, 'joined_at' => $status === RoomMember::ACTIVE ? now() : null],
        );
    }

    private function mustManage(User $actor, Room $room): void
    {
        if (! $room->isTutorRoom() || ! $this->access->canManage($actor, $room)) {
            throw CommunityRefusal::because('not_allowed', 'Only the tutor who runs this room can do that.', 403);
        }
    }

    private function code(): string
    {
        return Str::upper(Str::random(10));
    }
}
