<?php

namespace Tests\Feature\Classroom;

use App\Domain\Marketplace\Models\TutorSessionParticipant;
use App\Models\TutorProfile;
use App\Models\TutorSession;
use App\Models\User;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Safety\BuildsSafetyState;

/** Lessons and people for the classroom tests, built explicitly in each test. */
trait BuildsClassroom
{
    use BuildsSafetyState;

    /** @return array{0: User, 1: TutorProfile} */
    protected function tutor(string $name = 'Tutor Thandi', bool $screened = true): array
    {
        $user = $this->adult($name);
        if ($screened) {
            $this->screen($user);
        }

        return [$user, $this->makeTutor([], null, $user)];
    }

    /** A confirmed one-to-one lesson starting in $minutes (negative: already started). Written without the booking backstop. */
    protected function lesson(TutorProfile $profile, User $learner, int $minutes = 5, array $overrides = []): TutorSession
    {
        return TutorSession::withoutEvents(fn () => $this->makeSession($profile, $learner, $this->makeSubject('Mathematics'), array_merge([
            'status' => 'confirmed',
            'starts_at' => now()->addMinutes($minutes),
            'duration_minutes' => 60,
            'kind' => TutorSession::KIND_ONE_TO_ONE,
        ], $overrides)));
    }

    /** A confirmed group lesson with a confirmed seat for each learner. */
    protected function groupLesson(TutorProfile $profile, array $learners, int $minutes = 5): TutorSession
    {
        $session = TutorSession::withoutEvents(fn () => TutorSession::create([
            'tutor_profile_id' => $profile->id,
            'student_id' => null,
            'subject_id' => $this->makeSubject('Physical Sciences')->id,
            'kind' => TutorSession::KIND_GROUP,
            'capacity' => 10,
            'status' => 'confirmed',
            'starts_at' => now()->addMinutes($minutes),
            'duration_minutes' => 60,
            'rate' => 0,
        ]));
        foreach ($learners as $learner) {
            TutorSessionParticipant::query()->create(['tutor_session_id' => $session->id, 'learner_id' => $learner->id, 'status' => 'confirmed', 'price_minor' => 0, 'currency' => 'ZAR']);
        }

        return $session;
    }

    protected function joinAs(User $user, TutorSession $session, string $client = 'tab-a'): TestResponse
    {
        return $this->actingAs($user)->postJson(route('classroom.api.join', $session), ['client_id' => $client]);
    }

    protected function pollAs(User $user, TutorSession $session, string $client = 'tab-a', array $query = []): TestResponse
    {
        return $this->actingAs($user)->withHeader('X-Client-Id', $client)->postJson(route('classroom.api.poll', $session), $query);
    }
}
