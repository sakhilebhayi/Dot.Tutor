<?php

namespace Tests\Feature\Marketplace;

use App\Domain\Marketplace\Exceptions\MarketplaceException;
use App\Domain\Marketplace\Models\TutorPackagePurchase;
use App\Domain\Marketplace\Models\TutorSessionParticipant;
use App\Domain\Marketplace\Services\BookingFlow;
use App\Models\TutorBlockedDate as BlockedDate;
use App\Models\TutorSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsOfferings;
use Tests\TestCase;

class GroupSeriesPackageTest extends TestCase
{
    use BuildsOfferings;
    use RefreshDatabase;

    private function groupLesson(int $capacity = 2, int $price = 10000): array
    {
        [$tutor, $subject, $offering] = $this->bookableTutor([
            'type' => 'group', 'title' => 'Grade 12 Maths revision group', 'capacity' => $capacity, 'price_minor' => $price,
        ]);
        $id = $this->actingAs($tutor->user)->postJson("/api/tutor/me/offerings/{$offering->id}/group-sessions", [
            'subject_id' => $subject->id,
            'starts_at' => $this->slotAt($tutor, 3)->toIso8601String(),
        ])->assertCreated()->assertJsonPath('data.state', 'confirmed')->assertJsonPath('data.seats_left', $capacity)->json('data.id');

        return [$tutor, $subject, $offering, TutorSession::find($id)];
    }

    public function test_learners_take_seats_until_the_group_is_full(): void
    {
        [, , , $group] = $this->groupLesson(capacity: 2);

        $this->actingAs($this->makeUser('A'))->postJson("/api/tutor/group-sessions/{$group->id}/seats")->assertCreated()->assertJsonPath('data.state', 'awaiting_payment');
        $this->actingAs($this->makeUser('B'))->postJson("/api/tutor/group-sessions/{$group->id}/seats")->assertCreated();
        $this->actingAs($this->makeUser('C'))->postJson("/api/tutor/group-sessions/{$group->id}/seats")
            ->assertStatus(409)->assertJsonPath('error', 'full');

        $this->assertSame(2, $group->fresh()->seats_taken);
        $this->assertSame(2, TutorSessionParticipant::count());
    }

    public function test_two_learners_racing_for_the_last_seat_cannot_both_get_it(): void
    {
        [, , , $group] = $this->groupLesson(capacity: 1);
        $flow = app(BookingFlow::class);

        // Both requests read the session while it still showed one free seat.
        $staleForA = TutorSession::find($group->id);
        $staleForB = TutorSession::find($group->id);
        $this->assertSame(0, $staleForB->seats_taken);

        $flow->requestSeat($this->makeUser('A'), $staleForA);

        try {
            $flow->requestSeat($this->makeUser('B'), $staleForB);
            $this->fail('The second learner got a seat that was already taken.');
        } catch (MarketplaceException $e) {
            $this->assertSame('full', $e->error);
        }

        // Even a write that skipped every check cannot push the counter past capacity.
        $this->assertSame(0, \DB::table('tutor_sessions')->where('id', $group->id)->whereColumn('seats_taken', '<', 'capacity')->increment('seats_taken'));
        $this->assertSame(1, $group->fresh()->seats_taken);
        $this->assertSame(1, TutorSessionParticipant::count());
    }

    public function test_a_duplicate_seat_request_is_refused_or_replayed(): void
    {
        [, , , $group] = $this->groupLesson(capacity: 3);
        $learner = $this->makeUser();

        $first = $this->actingAs($learner)->withHeaders(['Idempotency-Key' => 'seat-1'])->postJson("/api/tutor/group-sessions/{$group->id}/seats")->assertCreated();
        $again = $this->actingAs($learner)->withHeaders(['Idempotency-Key' => 'seat-1'])->postJson("/api/tutor/group-sessions/{$group->id}/seats")->assertOk();
        $this->assertSame($first->json('data.id'), $again->json('data.id'));
        $this->assertTrue($again->json('replayed'));

        $this->actingAs($learner)->withHeaders(['Idempotency-Key' => 'seat-2'])->postJson("/api/tutor/group-sessions/{$group->id}/seats")
            ->assertStatus(409)->assertJsonPath('error', 'already_booked');
        $this->assertSame(1, $group->fresh()->seats_taken);
    }

    public function test_cancelling_a_seat_and_an_expired_payment_hold_free_the_place(): void
    {
        [, , , $group] = $this->groupLesson(capacity: 1);
        $a = $this->makeUser('A');
        $seat = $this->actingAs($a)->postJson("/api/tutor/group-sessions/{$group->id}/seats")->json('data.id');
        $this->actingAs($a)->postJson("/api/tutor/seats/{$seat}/cancel")->assertOk()->assertJsonPath('data.state', 'cancelled');
        $this->assertSame(0, $group->fresh()->seats_taken);

        $b = $this->makeUser('B');
        $this->actingAs($b)->postJson("/api/tutor/group-sessions/{$group->id}/seats")->assertCreated();
        $this->travel(31)->minutes();

        // The next learner finds the place free: B's unpaid hold ran out.
        $this->actingAs($this->makeUser('C'))->postJson("/api/tutor/group-sessions/{$group->id}/seats")->assertCreated();
        $this->assertSame('cancelled', TutorSessionParticipant::where('learner_id', $b->id)->value('status'));
        $this->assertSame(1, $group->fresh()->seats_taken);
    }

    public function test_a_paid_seat_is_confirmed_and_the_attending_learner_can_review(): void
    {
        $this->fakePayingCheckout();
        [$tutor, , , $group] = $this->groupLesson(capacity: 2);
        $learner = $this->makeUser();
        $seat = $this->actingAs($learner)->postJson("/api/tutor/group-sessions/{$group->id}/seats")->json('data.id');
        $this->actingAs($learner)->postJson("/api/tutor/seats/{$seat}/pay")->assertOk();
        $this->assertSame('confirmed', TutorSessionParticipant::find($seat)->status);

        $this->travelTo($group->starts_at->copy()->addMinutes(70));
        $this->actingAs($tutor->user)->postJson("/api/tutor/bookings/{$group->id}/complete")->assertJsonPath('data.state', 'completed');
        $this->assertSame('attended', TutorSessionParticipant::find($seat)->status);

        $this->actingAs($learner)->postJson("/api/tutor/bookings/{$group->id}/reviews", ['rating' => 5, 'review' => 'Clear and patient.'])->assertCreated();
        $this->actingAs($this->makeUser('Not there'))->postJson("/api/tutor/bookings/{$group->id}/reviews", ['rating' => 1])->assertForbidden();
    }

    public function test_the_tutor_cancelling_a_group_cancels_every_seat(): void
    {
        [$tutor, , , $group] = $this->groupLesson(capacity: 3, price: 0);
        $this->actingAs($this->makeUser('A'))->postJson("/api/tutor/group-sessions/{$group->id}/seats")->assertJsonPath('data.state', 'confirmed');
        $this->actingAs($this->makeUser('B'))->postJson("/api/tutor/group-sessions/{$group->id}/seats");

        $this->actingAs($tutor->user)->postJson("/api/tutor/bookings/{$group->id}/cancel", ['reason' => 'Ill.'])->assertOk();

        $this->assertSame(0, TutorSessionParticipant::whereIn('status', ['confirmed', 'awaiting_payment'])->count());
        $this->assertSame(0, $group->fresh()->seats_taken);
    }

    public function test_a_tutor_cannot_schedule_a_group_over_their_own_booking(): void
    {
        [$tutor, $subject, $offering] = $this->bookableTutor(['type' => 'group', 'capacity' => 4]);
        $this->makeSession($tutor, $this->makeUser(), $subject, ['starts_at' => $this->slotAt($tutor, 3)]);

        $this->actingAs($tutor->user)->postJson("/api/tutor/me/offerings/{$offering->id}/group-sessions", [
            'subject_id' => $subject->id, 'starts_at' => $this->slotAt($tutor, 3)->toIso8601String(),
        ])->assertStatus(422);
    }

    public function test_weekly_lessons_book_each_week_and_report_skipped_ones(): void
    {
        [$tutor, $subject, $offering] = $this->bookableTutor(['type' => 'recurring_weekly', 'title' => 'Weekly maths', 'recurring_weeks' => 4]);
        $first = $this->slotAt($tutor, 2, '16:00');
        BlockedDate::create(['tutor_profile_id' => $tutor->id, 'date' => $first->copy()->addWeeks(2)->toDateString()]);
        $learner = $this->makeUser();

        $response = $this->actingAs($learner)->postJson("/api/tutor/offerings/{$offering->id}/series", [
            'subject_id' => $subject->id, 'starts_at' => $first->toIso8601String(),
        ])->assertCreated();

        $this->assertCount(3, $response->json('data.sessions'));
        $this->assertCount(1, $response->json('skipped'));
        $this->assertSame('16:00', $response->json('data.local_time'));

        $series = $response->json('data.id');
        $this->actingAs($tutor->user)->postJson("/api/tutor/series/{$series}/accept")->assertOk()->assertJsonPath('accepted', 3);
        $this->assertSame(3, TutorSession::where('tutor_booking_series_id', $series)->where('status', 'awaiting_payment')->count());
    }

    public function test_a_package_gives_credits_that_bookings_use_and_cancellations_return(): void
    {
        $this->fakePayingCheckout();
        [$tutor, $subject, $package] = $this->bookableTutor(['type' => 'package', 'title' => '4 lessons', 'package_lessons' => 2, 'price_minor' => 100000, 'package_valid_days' => 90]);
        $learner = $this->makeUser();

        $purchase = $this->actingAs($learner)->postJson("/api/tutor/offerings/{$package->id}/packages")
            ->assertCreated()->assertJsonPath('data.state', 'awaiting_payment')->json('data.id');

        // Not usable before payment.
        $this->actingAs($learner)->postJson("/api/tutor/offerings/{$package->id}/bookings", [
            'subject_id' => $subject->id, 'starts_at' => $this->slotAt($tutor, 2)->toIso8601String(), 'package_purchase_id' => $purchase,
        ])->assertStatus(409)->assertJsonPath('error', 'package_not_active');

        $this->actingAs($learner)->postJson("/api/tutor/packages/{$purchase}/pay")->assertOk();
        $this->assertSame('active', TutorPackagePurchase::find($purchase)->status);

        $book = fn (int $day) => $this->actingAs($learner)->postJson("/api/tutor/offerings/{$package->id}/bookings", [
            'subject_id' => $subject->id, 'starts_at' => $this->slotAt($tutor, $day)->toIso8601String(), 'package_purchase_id' => $purchase,
        ]);
        $one = $book(2)->assertCreated()->assertJsonPath('data.payment_status', 'covered_by_package')->json('data.id');
        $book(3)->assertCreated();
        $this->assertSame('exhausted', TutorPackagePurchase::find($purchase)->status);
        $book(4)->assertStatus(409);

        // Covered lessons are confirmed on acceptance (nothing more to pay).
        $this->actingAs($tutor->user)->postJson("/api/tutor/bookings/{$one}/accept")->assertJsonPath('data.state', 'confirmed');
        $this->actingAs($tutor->user)->postJson("/api/tutor/bookings/{$one}/cancel")->assertOk();
        $this->assertSame(1, TutorPackagePurchase::find($purchase)->creditsLeft());
        $this->assertSame('active', TutorPackagePurchase::find($purchase)->status);
    }

    public function test_another_learner_cannot_spend_my_package(): void
    {
        [$tutor, $subject, $package] = $this->bookableTutor(['type' => 'package', 'package_lessons' => 2, 'price_minor' => 0]);
        $owner = $this->makeUser('Owner');
        $purchase = $this->actingAs($owner)->postJson("/api/tutor/offerings/{$package->id}/packages")->assertJsonPath('data.state', 'active')->json('data.id');

        $this->actingAs($this->makeUser('Thief'))->postJson("/api/tutor/offerings/{$package->id}/bookings", [
            'subject_id' => $subject->id, 'starts_at' => $this->slotAt($tutor, 2)->toIso8601String(), 'package_purchase_id' => $purchase,
        ])->assertNotFound()->assertJsonPath('error', 'package_not_found');
    }
}
