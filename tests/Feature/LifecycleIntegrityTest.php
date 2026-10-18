<?php

namespace Tests\Feature;

use App\Enums\SessionStatus;
use App\Enums\TutorProfileStatus;
use App\Models\SessionRating;
use App\Models\TutorProfile;
use App\Services\RatingService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BuildsMarketplace;
use Tests\TestCase;

class LifecycleIntegrityTest extends TestCase
{
    use BuildsMarketplace;
    use RefreshDatabase;

    public function test_every_status_in_use_is_declared_in_one_place(): void
    {
        $this->assertEqualsCanonicalizing(
            ['pending', 'confirmed', 'completed', 'cancelled', 'no_show'],
            SessionStatus::values(),
        );
        $this->assertEqualsCanonicalizing(
            ['pending', 'approved', 'rejected', 'suspended'],
            TutorProfileStatus::values(),
        );
        $this->assertSame(['pending', 'confirmed'], SessionStatus::holdingTime());
    }

    public function test_the_stale_session_command_writes_the_declared_no_show_status(): void
    {
        $subject = $this->makeSubject();
        $profile = $this->makeTutor([], $subject);
        $session = $this->makeSession($profile, $this->makeUser(), $subject, ['starts_at' => now()->subDays(2)]);

        $this->artisan('tutor:expire-stale-sessions')->assertSuccessful();

        $this->assertSame(SessionStatus::NoShow->value, $session->fresh()->status);
    }

    public function test_a_suspended_tutor_cannot_confirm_a_pending_session(): void
    {
        $subject = $this->makeSubject();
        $profile = $this->makeTutor(['status' => 'suspended'], $subject);
        $session = $this->makeSession($profile, $this->makeUser(), $subject);

        $this->actingAs($profile->user)->post(route('sessions.confirm', $session))->assertForbidden();
        $this->assertSame('pending', $session->fresh()->status);
    }

    public function test_neither_party_can_complete_a_session_of_a_suspended_tutor(): void
    {
        $subject = $this->makeSubject();
        $profile = $this->makeTutor(['status' => 'suspended'], $subject);
        $student = $this->makeUser();
        $session = $this->makeSession($profile, $student, $subject, ['status' => 'confirmed', 'starts_at' => now()->subHour()]);

        $this->actingAs($profile->user)->post(route('sessions.complete', $session))->assertForbidden();
        $this->actingAs($student)->post(route('sessions.complete', $session))->assertForbidden();
        $this->assertSame('confirmed', $session->fresh()->status);
        $this->assertSame(0, $profile->fresh()->total_sessions);
    }

    public function test_a_pending_or_rejected_tutor_cannot_confirm_either(): void
    {
        $subject = $this->makeSubject();
        foreach (['pending', 'rejected'] as $status) {
            $profile = $this->makeTutor(['status' => $status], $subject, $this->makeUser("T-$status"));
            $session = $this->makeSession($profile, $this->makeUser(), $subject);
            $this->actingAs($profile->user)->post(route('sessions.confirm', $session))->assertForbidden();
        }
    }

    public function test_a_suspended_tutors_participants_can_still_cancel(): void
    {
        $subject = $this->makeSubject();
        $profile = $this->makeTutor(['status' => 'suspended'], $subject);
        $student = $this->makeUser();
        $session = $this->makeSession($profile, $student, $subject, ['status' => 'confirmed']);

        $this->actingAs($student)->post(route('sessions.cancel', $session))->assertRedirect();

        $this->assertSame('cancelled', $session->fresh()->status);
    }

    public function test_suspending_a_tutor_cancels_their_pending_sessions_and_leaves_the_rest(): void
    {
        $subject = $this->makeSubject();
        $profile = $this->makeTutor([], $subject);
        $other = $this->makeTutor([], $subject, $this->makeUser('Other Tutor'));
        $student = $this->makeUser();
        $pending = $this->makeSession($profile, $student, $subject, ['starts_at' => now()->addDays(2)]);
        $confirmed = $this->makeSession($profile, $student, $subject, ['status' => 'confirmed', 'starts_at' => now()->addDays(3)]);
        $completed = $this->makeSession($profile, $student, $subject, ['status' => 'completed', 'starts_at' => now()->subDays(3)]);
        $othersPending = $this->makeSession($other, $student, $subject, ['starts_at' => now()->addDays(4)]);

        $this->actingAs($this->makeOperator())
            ->post("/operator/tutor-profiles/{$profile->id}/suspend", ['reason' => 'Policy breach'])
            ->assertRedirect();

        $this->assertSame('suspended', $profile->fresh()->status);
        $this->assertSame('Policy breach', $profile->fresh()->suspended_reason);
        $this->assertSame('cancelled', $pending->fresh()->status);
        $this->assertSame('confirmed', $confirmed->fresh()->status);
        $this->assertSame('completed', $completed->fresh()->status);
        $this->assertSame('pending', $othersPending->fresh()->status);
    }

    public function test_a_suspension_needs_a_reason_and_an_approved_profile(): void
    {
        $subject = $this->makeSubject();
        $profile = $this->makeTutor([], $subject);
        $pending = $this->makeTutor(['status' => 'pending'], $subject, $this->makeUser('Pending'));
        $operator = $this->makeOperator();

        $this->actingAs($operator)->post("/operator/tutor-profiles/{$profile->id}/suspend", ['reason' => ''])->assertSessionHasErrors('reason');
        $this->actingAs($operator)->post("/operator/tutor-profiles/{$pending->id}/suspend", ['reason' => 'x'])->assertRedirect();

        $this->assertSame('approved', $profile->fresh()->status);
        $this->assertSame('pending', $pending->fresh()->status);
    }

    public function test_an_operator_can_reinstate_a_suspended_tutor(): void
    {
        $profile = $this->makeTutor(['status' => 'suspended', 'suspended_reason' => 'Because']);

        $this->actingAs($this->makeOperator())->post("/operator/tutor-profiles/{$profile->id}/reinstate")->assertRedirect();

        $this->assertSame('approved', $profile->fresh()->status);
        $this->assertNull($profile->fresh()->suspended_reason);
    }

    public function test_only_operators_can_suspend_or_reinstate(): void
    {
        $profile = $this->makeTutor();

        $this->actingAs($this->makeUser())->post("/operator/tutor-profiles/{$profile->id}/suspend", ['reason' => 'x'])->assertForbidden();
        $this->actingAs($this->makeUser())->post("/operator/tutor-profiles/{$profile->id}/reinstate")->assertForbidden();
    }

    public function test_the_database_rejects_a_rating_outside_one_to_five(): void
    {
        $subject = $this->makeSubject();
        $profile = $this->makeTutor([], $subject);
        $student = $this->makeUser();
        $sessions = collect(range(1, 4))->map(fn () => $this->makeSession($profile, $student, $subject, ['status' => 'completed', 'starts_at' => now()->subDays(2)]));

        foreach ([0, 6, 99] as $i => $bad) {
            try {
                DB::table('session_ratings')->insert(['session_id' => $sessions[$i]->id, 'rated_by' => $student->id, 'rating' => $bad, 'created_at' => now(), 'updated_at' => now()]);
                $this->fail("rating {$bad} was accepted by the database");
            } catch (QueryException) {
                $this->assertTrue(true);
            }
        }

        DB::table('session_ratings')->insert(['session_id' => $sessions[3]->id, 'rated_by' => $student->id, 'rating' => 5, 'created_at' => now(), 'updated_at' => now()]);
        $this->assertSame(1, SessionRating::count());

        $this->expectException(QueryException::class);
        DB::table('session_ratings')->where('session_id', $sessions[3]->id)->update(['rating' => 8]);
    }

    public function test_rating_and_tutor_average_commit_together_or_not_at_all(): void
    {
        $subject = $this->makeSubject();
        $profile = $this->makeTutor([], $subject);
        $student = $this->makeUser();
        $session = $this->makeSession($profile, $student, $subject, ['status' => 'completed', 'starts_at' => now()->subDays(2)]);

        TutorProfile::updating(function () {
            throw new \RuntimeException('average update failed');
        });

        $this->withoutExceptionHandling();
        try {
            $this->actingAs($student)->post(route('sessions.rating.store', $session), ['rating' => 5]);
            $this->fail('expected the failing average update to surface');
        } catch (\RuntimeException $e) {
            $this->assertSame('average update failed', $e->getMessage());
        }

        $this->assertSame(0, SessionRating::count(), 'the rating row must roll back with the failed average update');
    }

    public function test_refresh_rating_is_idempotent(): void
    {
        $subject = $this->makeSubject();
        $profile = $this->makeTutor([], $subject);
        $student = $this->makeUser();
        foreach ([5, 4, 4] as $stars) {
            $s = $this->makeSession($profile, $student, $subject, ['status' => 'completed', 'starts_at' => now()->subDays(2)]);
            SessionRating::create(['session_id' => $s->id, 'rated_by' => $student->id, 'rating' => $stars]);
        }

        $profile->refreshRating();
        $first = $profile->fresh()->rating;
        $profile->refreshRating();
        $profile->refreshRating();

        $this->assertEquals('4.33', $first);
        $this->assertEquals($first, $profile->fresh()->rating);
    }

    public function test_refresh_rating_with_no_ratings_is_zero_and_stays_zero(): void
    {
        $profile = $this->makeTutor();
        $profile->refreshRating();
        $profile->refreshRating();

        $this->assertEquals(0, $profile->fresh()->rating);
    }

    public function test_a_double_submitted_rating_is_refused_cleanly_not_with_a_server_error(): void
    {
        $subject = $this->makeSubject();
        $profile = $this->makeTutor([], $subject);
        $student = $this->makeUser();
        $session = $this->makeSession($profile, $student, $subject, ['status' => 'completed', 'starts_at' => now()->subDays(2)]);
        SessionRating::create(['session_id' => $session->id, 'rated_by' => $student->id, 'rating' => 3]);

        // The policy would normally stop this; call the service directly as a racing request would.
        $result = app(RatingService::class)->rate($session, $student, 5, null);

        $this->assertFalse($result);
        $this->assertSame(1, SessionRating::count());
        $this->assertSame(3, SessionRating::firstOrFail()->rating);
    }

    public function test_completing_twice_in_a_race_counts_the_session_once(): void
    {
        $subject = $this->makeSubject();
        $profile = $this->makeTutor([], $subject);
        $student = $this->makeUser();
        $session = $this->makeSession($profile, $student, $subject, ['status' => 'confirmed', 'starts_at' => now()->subHour()]);

        $this->actingAs($student)->post(route('sessions.complete', $session))->assertRedirect();
        // A stale second click: the policy now refuses it.
        $this->actingAs($student)->post(route('sessions.complete', $session))->assertForbidden();

        $this->assertSame(1, $profile->fresh()->total_sessions);
    }
}
