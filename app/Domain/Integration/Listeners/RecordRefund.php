<?php

namespace App\Domain\Integration\Listeners;

use App\Domain\Community\Models\Room;
use App\Domain\Community\Models\RoomMember;
use App\Domain\Learning\Models\Bundle;
use App\Domain\Learning\Models\Enrolment;
use App\Domain\Marketplace\Models\TutorPackagePurchase;
use App\Domain\Marketplace\Models\TutorSessionParticipant;
use App\Domain\Marketplace\Services\BookingStateMachine;
use App\Domain\Money\Events\OrderRefunded;
use App\Domain\Money\Models\OrderLine;
use App\Domain\Money\Models\Refund;
use App\Domain\Safety\Audit;
use App\Models\TutorSession;
use Illuminate\Support\Facades\DB;

/**
 * A processed refund, told to the domain that sold the item. Money has already revoked access (entitlements) for a
 * full refund; here a fully refunded course closes the enrolment (status "refunded", progress kept), and a lesson or
 * seat gets a "refunded" event on its booking timeline.
 */
class RecordRefund
{
    public function __construct(private readonly BookingStateMachine $machine) {}

    public function handle(OrderRefunded $event): void
    {
        $line = OrderLine::query()->with('order')->find($event->orderLineId);
        $refund = Refund::query()->find($event->refundId);

        if ($line === null || $line->order === null) {
            return;
        }

        $id = (int) $line->purchasable_id;
        $context = ['order_reference' => $line->order->uuid, 'refund_id' => $event->refundId,
            'amount_minor' => (int) ($refund?->amount_minor ?? 0), 'fully_refunded' => $event->fullyRefunded];

        match ($line->purchasable_type) {
            'booking' => ($session = TutorSession::query()->find($id))
                ? $this->machine->record($session, 'refunded', null, $session->status, $session->status, null, $context) : null,
            'seat' => ($seat = TutorSessionParticipant::query()->find($id)) && $seat->session
                ? $this->machine->record($seat->session, 'seat_refunded', null, $seat->status, $seat->status, null, $context, $seat->id) : null,
            'course' => $event->fullyRefunded ? $this->closeEnrolments([$id], $line->order->buyer_id) : null,
            'package' => $event->fullyRefunded ? $this->closePackage($id, $line->order->buyer_id) : null,
            'room' => $event->fullyRefunded ? $this->closeRoomPlace($id, $line->order->buyer_id) : null,
            'course_bundle' => $event->fullyRefunded
                ? $this->closeEnrolments(Bundle::query()->find($id)?->courses()->pluck('learning_courses.id')->map(fn ($v) => (int) $v)->all() ?? [], $line->order->buyer_id) : null,
            default => null,
        };
    }

    /**
     * A fully refunded package stops covering lessons (R-47 security S-11): otherwise the learner has the money
     * back and keeps booking the remaining lessons, which the tutor teaches unpaid. Lessons already booked stay.
     */
    private function closePackage(int $packagePurchaseId, int $buyerId): void
    {
        TutorPackagePurchase::query()->whereKey($packagePurchaseId)->where('learner_id', $buyerId)
            ->whereIn('status', [TutorPackagePurchase::ACTIVE, TutorPackagePurchase::AWAITING_PAYMENT, TutorPackagePurchase::AWAITING_GUARDIAN])
            ->update(['status' => TutorPackagePurchase::CANCELLED, 'updated_at' => now()]);
    }

    /** A fully refunded paid room place ends, and its seat is freed (R-47 security S-12). */
    private function closeRoomPlace(int $roomId, int $buyerId): void
    {
        DB::transaction(function () use ($roomId, $buyerId) {
            $member = RoomMember::query()->where('room_id', $roomId)->where('user_id', $buyerId)
                ->where('role', RoomMember::LEARNER)->whereIn('status', [RoomMember::ACTIVE, RoomMember::AWAITING_PAYMENT])
                ->lockForUpdate()->first();

            if ($member === null) {
                return;
            }

            $wasActive = $member->status === RoomMember::ACTIVE;
            $member->forceFill(['status' => RoomMember::REMOVED])->save();

            if ($wasActive) {
                Room::query()->whereKey($roomId)->where('seats_taken', '>', 0)->decrement('seats_taken');
            }

            Audit::record(null, 'community.room.place_refunded', $member, ['room_id' => $roomId, 'user_id' => $buyerId]);
        });
    }

    /** @param  list<int>  $courseIds */
    private function closeEnrolments(array $courseIds, int $userId): void
    {
        // Only enrolments that rest on a purchase; a learner who enrolled while the course was free keeps access.
        Enrolment::query()->whereIn('course_id', $courseIds)->where('user_id', $userId)
            ->where('source', 'entitlement')->where('status', 'active')
            ->update(['status' => 'refunded', 'updated_at' => now()]);
    }
}
