<?php

namespace Tests\Feature;

use App\Models\Subject;
use App\Models\TutorProfile;
use App\Models\TutorSession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Regression test for a cross-user information disclosure found during the
 * Aug 2026 ecosystem-integration pass: the learner home's (/dashboard now redirects there, R-51) "Upcoming
 * Sessions" and "Recent Sessions" panels queried TutorSession::with(...)
 * with no scoping at all, so any authenticated user — student or tutor,
 * regardless of involvement — could see every other user's booked session
 * details (who they're tutoring/being tutored by, subject, time, and the
 * dollar amount charged). There is no admin/staff role in this schema to
 * justify a platform-wide view on this route, so the fix scopes both lists
 * to sessions the signed-in user is actually part of (as student or as the
 * owner of the tutor profile).
 *
 * Written but NOT executed locally — this environment has no PHP/Composer/
 * PostgreSQL available (see Dot.Brain/os/02-Engineering-Loop.md §2).
 */
class DashboardSessionScopingTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_learner_home_does_not_leak_other_users_session_details(): void
    {
        $subject = Subject::create(['name' => 'Algebra', 'level' => 'high_school']);

        $tutorUser = User::factory()->withPersonalTeam()->create(['name' => 'Other Tutor']);
        $tutorProfile = TutorProfile::create([
            'user_id' => $tutorUser->id,
            'hourly_rate' => 40,
            'status' => 'approved',
        ]);

        $otherStudent = User::factory()->withPersonalTeam()->create(['name' => 'Other Student']);
        TutorSession::create([
            'tutor_profile_id' => $tutorProfile->id,
            'student_id' => $otherStudent->id,
            'subject_id' => $subject->id,
            'status' => 'confirmed',
            'starts_at' => now()->addDay(),
            'duration_minutes' => 60,
            'rate' => 40,
        ]);

        $me = User::factory()->withPersonalTeam()->create();

        $response = $this->actingAs($me)->get('/learn');

        $response->assertOk();
        $response->assertDontSee('Other Student');
        // The other tutor's name should not appear via a session row either,
        // since $me has no session with them.
        $response->assertDontSee('Other Tutor');
    }

    public function test_the_learner_home_shows_the_signed_in_students_own_session(): void
    {
        $subject = Subject::create(['name' => 'Physics', 'level' => 'high_school']);

        $tutorUser = User::factory()->withPersonalTeam()->create(['name' => 'My Tutor']);
        $tutorProfile = TutorProfile::create([
            'user_id' => $tutorUser->id,
            'hourly_rate' => 50,
            'status' => 'approved',
        ]);

        $me = User::factory()->withPersonalTeam()->create();
        TutorSession::create([
            'tutor_profile_id' => $tutorProfile->id,
            'student_id' => $me->id,
            'subject_id' => $subject->id,
            'status' => 'confirmed',
            'starts_at' => now()->addDay(),
            'duration_minutes' => 60,
            'rate' => 50,
        ]);

        $response = $this->actingAs($me)->get('/learn');

        $response->assertOk();
        $response->assertSee('My Tutor');
    }
}
