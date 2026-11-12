<?php

namespace Tests\Feature;

use App\Models\Subject;
use App\Models\TutorProfile;
use App\Models\TutorSession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** QA route sweep regression: these pages had no h1 (sign-in, legal pages, account and legacy pages). */
class SingleHeadingTest extends TestCase
{
    use RefreshDatabase;

    private function assertOneH1(string $html, string $url): void
    {
        $this->assertSame(1, substr_count(strtolower($html), '<h1'), "{$url} has exactly one h1");
    }

    public function test_guest_pages_have_one_heading(): void
    {
        foreach (['/login', '/forgot-password', '/terms-of-service', '/privacy-policy', '/cookies'] as $url) {
            $this->assertOneH1($this->get($url)->assertOk()->getContent(), $url);
        }
    }

    public function test_account_and_legacy_pages_have_one_heading(): void
    {
        $tutorUser = User::factory()->withPersonalTeam()->create();
        $learner = User::factory()->withPersonalTeam()->create();
        $subject = Subject::create(['name' => 'Algebra', 'level' => 'high_school']);
        $profile = TutorProfile::create(['user_id' => $tutorUser->id, 'hourly_rate' => 250, 'status' => 'approved']);
        $profile->subjects()->attach($subject->id);
        $session = TutorSession::create(['tutor_profile_id' => $profile->id, 'student_id' => $learner->id, 'subject_id' => $subject->id,
            'status' => 'confirmed', 'starts_at' => now()->addDay(), 'duration_minutes' => 60, 'rate' => 250]);

        foreach (['/user/profile', '/teams/create', "/tutors/{$profile->id}", "/sessions/{$session->id}"] as $url) {
            $this->assertOneH1($this->actingAs($learner)->get($url)->assertOk()->getContent(), $url);
        }
    }
}
