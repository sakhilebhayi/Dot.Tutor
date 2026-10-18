<?php

namespace Tests\Feature;

use App\Models\Subject;
use App\Models\TutorProfile;
use App\Models\TutorSession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsMarketplace;
use Tests\TestCase;

/**
 * Covers the booking flow built to close the "no booking UI exists" gap
 * flagged during the Aug 2026 ecosystem-integration pass. This is the one
 * test suite in this repository actually executed against a real database
 * (PHP 8.5 + PostgreSQL, installed and run 2026-08-02) rather than written
 * blind — see Dot.Brain/os/13-Engineering-State.md §4 for the environment
 * history this closes.
 */
class TutorBookingTest extends TestCase
{
    use BuildsMarketplace;
    use RefreshDatabase;

    private function approvedTutor(string $tutorSubject = 'Algebra'): array
    {
        $subject = Subject::create(['name' => $tutorSubject, 'level' => 'high_school']);
        $tutorUser = User::factory()->withPersonalTeam()->create(['name' => 'Approved Tutor']);
        $tutorProfile = TutorProfile::create([
            'user_id' => $tutorUser->id,
            'hourly_rate' => 45,
            'status' => 'approved',
            'timezone' => 'UTC',
        ]);
        $tutorProfile->subjects()->attach($subject->id);
        $this->openWeek($tutorProfile);

        return [$tutorProfile, $subject];
    }

    public function test_browse_only_lists_approved_tutors(): void
    {
        [$approved] = $this->approvedTutor();

        $pendingUser = User::factory()->withPersonalTeam()->create(['name' => 'Pending Tutor']);
        TutorProfile::create(['user_id' => $pendingUser->id, 'hourly_rate' => 30, 'status' => 'pending']);

        $student = User::factory()->withPersonalTeam()->create();

        $response = $this->actingAs($student)->get('/tutors');

        $response->assertOk();
        $response->assertSee('Approved Tutor');
        $response->assertDontSee('Pending Tutor');
    }

    public function test_student_can_book_a_session_with_an_approved_tutor(): void
    {
        [$tutorProfile, $subject] = $this->approvedTutor();
        $student = User::factory()->withPersonalTeam()->create();

        $response = $this->actingAs($student)->post("/tutors/{$tutorProfile->id}/sessions", [
            'subject_id' => $subject->id,
            'starts_at' => now()->addDays(2)->setTime(10, 0)->format('Y-m-d\TH:i'),
            'duration_minutes' => 60,
            'delivery' => 'online',
            'notes' => 'Need help with quadratics.',
        ]);

        $session = TutorSession::firstOrFail();
        $response->assertRedirect(route('sessions.show', $session));

        $this->assertSame($student->id, $session->student_id);
        $this->assertSame($tutorProfile->id, $session->tutor_profile_id);
        $this->assertSame('pending', $session->status);
        $this->assertEquals(45, $session->rate);
    }

    public function test_a_tutor_cannot_book_a_session_with_themselves(): void
    {
        [$tutorProfile, $subject] = $this->approvedTutor();

        $response = $this->actingAs($tutorProfile->user)->post("/tutors/{$tutorProfile->id}/sessions", [
            'subject_id' => $subject->id,
            'starts_at' => now()->addDays(2)->setTime(10, 0)->format('Y-m-d\TH:i'),
            'duration_minutes' => 60,
            'delivery' => 'online',
        ]);

        $response->assertForbidden();
        $this->assertSame(0, TutorSession::count());
    }

    public function test_booking_a_subject_the_tutor_does_not_teach_is_rejected(): void
    {
        [$tutorProfile] = $this->approvedTutor('Algebra');
        $unrelatedSubject = Subject::create(['name' => 'History', 'level' => 'high_school']);
        $student = User::factory()->withPersonalTeam()->create();

        $response = $this->actingAs($student)->post("/tutors/{$tutorProfile->id}/sessions", [
            'subject_id' => $unrelatedSubject->id,
            'starts_at' => now()->addDays(2)->setTime(10, 0)->format('Y-m-d\TH:i'),
            'duration_minutes' => 60,
            'delivery' => 'online',
        ]);

        $response->assertSessionHasErrors('subject_id');
        $this->assertSame(0, TutorSession::count());
    }

    public function test_a_user_cannot_view_a_session_they_are_not_part_of(): void
    {
        [$tutorProfile, $subject] = $this->approvedTutor();
        $student = User::factory()->withPersonalTeam()->create();
        $session = TutorSession::create([
            'tutor_profile_id' => $tutorProfile->id,
            'student_id' => $student->id,
            'subject_id' => $subject->id,
            'status' => 'pending',
            'starts_at' => now()->addDay(),
            'duration_minutes' => 60,
            'rate' => 45,
        ]);

        $outsider = User::factory()->withPersonalTeam()->create();

        $response = $this->actingAs($outsider)->get(route('sessions.show', $session));

        $response->assertForbidden();
    }

    public function test_a_student_can_cancel_their_own_pending_session(): void
    {
        [$tutorProfile, $subject] = $this->approvedTutor();
        $student = User::factory()->withPersonalTeam()->create();
        $session = TutorSession::create([
            'tutor_profile_id' => $tutorProfile->id,
            'student_id' => $student->id,
            'subject_id' => $subject->id,
            'status' => 'pending',
            'starts_at' => now()->addDay(),
            'duration_minutes' => 60,
            'rate' => 45,
        ]);

        $response = $this->actingAs($student)->post(route('sessions.cancel', $session));

        $response->assertRedirect(route('sessions.show', $session));
        $this->assertSame('cancelled', $session->fresh()->status);
    }
}
