<?php

namespace Database\Seeders;

use App\Domain\Learning\Enums\CourseStatus;
use App\Domain\Learning\Models\Assessment;
use App\Domain\Learning\Models\Course;
use App\Domain\Learning\Models\QuestionBank;
use App\Domain\Learning\Services\CoursePublisher;
use App\Models\Subject;
use App\Models\TutorProfile;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

/**
 * Local and E2E only: a demo tutor, a demo learner, one published course and one practice quiz, all clearly named
 * "Demo". Refuses to run in production. Run with: php artisan db:seed --class=DemoLearningSeeder
 */
class DemoLearningSeeder extends Seeder
{
    public function run(): void
    {
        if (app()->environment('production')) {
            throw new RuntimeException('DemoLearningSeeder must never run in production.');
        }

        $password = (string) env('DEMO_PASSWORD', 'password');
        $tutor = User::query()->firstOrCreate(['email' => 'demo.tutor@example.test'], ['name' => 'Demo Tutor', 'password' => Hash::make($password), 'email_verified_at' => now()]);
        $learner = User::query()->firstOrCreate(['email' => 'demo.learner@example.test'], ['name' => 'Demo Learner', 'password' => Hash::make($password), 'email_verified_at' => now()]);
        foreach ([$tutor, $learner] as $user) {
            if ($user->currentTeam === null) {
                $team = $user->ownedTeams()->create(['name' => $user->name."'s Team", 'personal_team' => true]);
                $user->forceFill(['current_team_id' => $team->id])->save();
            }
        }
        TutorProfile::query()->firstOrCreate(['user_id' => $tutor->id], ['hourly_rate' => 250, 'status' => 'approved', 'headline' => 'Demo tutor profile']);
        $subject = Subject::query()->firstOrCreate(['name' => 'Mathematics'], ['level' => 'high_school']);

        $course = Course::query()->firstOrCreate(['slug' => 'demo-algebra-foundations'], [
            'owner_id' => $tutor->id,
            'title' => 'Demo: Grade 10 algebra foundations',
            'description' => 'Demo course for local checks: factorising and linear equations with worked examples.',
            'subject_id' => $subject->id,
            'original_locale' => 'en',
            'objectives' => ['Factorise trinomials', 'Solve linear equations'],
            'prerequisites' => ['Grade 9 algebra'],
            'workload_minutes' => 120,
            'pricing' => 'free',
            'currency' => 'ZAR',
            'completion_rule' => 'all_lessons',
            'status' => CourseStatus::Draft,
        ]);
        if ($course->sections()->doesntExist()) {
            $section = $course->sections()->create(['title' => 'Factorising', 'position' => 1]);
            $section->lessons()->create(['course_id' => $course->id, 'title' => 'Common factors', 'type' => 'text', 'body' => "Take out the **highest common factor** first.\n\nExample: 6x + 9 = 3(2x + 3).", 'is_preview' => true, 'position' => 1, 'duration_minutes' => 10]);
            $section->lessons()->create(['course_id' => $course->id, 'title' => 'Trinomials', 'type' => 'text', 'body' => 'x² + 5x + 6 = (x + 2)(x + 3): find two numbers that multiply to 6 and add to 5.', 'position' => 2, 'duration_minutes' => 15]);
            app(CoursePublisher::class)->publish($course->fresh(), $tutor);
        }

        $bank = QuestionBank::query()->firstOrCreate(['owner_id' => $tutor->id, 'title' => 'Demo: factorising'], ['subject_id' => $subject->id, 'strand' => 'Algebra', 'topic' => 'Factorising']);
        if ($bank->questions()->doesntExist()) {
            $q1 = $bank->questions()->create(['type' => 'single_choice', 'prompt' => 'Factorise x² + 5x + 6', 'options' => [['key' => 'a', 'text' => '(x + 2)(x + 3)'], ['key' => 'b', 'text' => '(x + 1)(x + 6)']], 'answer' => ['key' => 'a'], 'explanation' => '2 × 3 = 6 and 2 + 3 = 5.']);
            $q2 = $bank->questions()->create(['type' => 'numeric', 'prompt' => 'Solve 2x + 4 = 10', 'answer' => ['value' => 3, 'tolerance' => 0]]);
            $assessment = Assessment::query()->create(['owner_id' => $tutor->id, 'course_id' => $course->id, 'title' => 'Demo: factorising practice', 'kind' => 'practice']);
            $assessment->questions()->attach([$q1->id => ['position' => 1], $q2->id => ['position' => 2]]);
        }
    }
}
