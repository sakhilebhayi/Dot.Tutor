<?php

namespace Database\Seeders;

use App\Domain\Classroom\Models\Classroom;
use App\Domain\Safety\Models\AgeRecord;
use App\Models\Subject;
use App\Models\TutorProfile;
use App\Models\TutorSession;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

/**
 * Local and E2E only: a demo tutor and a demo learner (both adults, clearly named "Demo") with one confirmed one-to-one
 * lesson that starts in five minutes, so the classroom is open. Re-running moves the lesson to start five minutes from
 * now again and empties its classroom. Refuses to run in production. Run with: php artisan db:seed --class=DemoClassroomSeeder
 */
class DemoClassroomSeeder extends Seeder
{
    public function run(): void
    {
        if (app()->environment('production')) {
            throw new RuntimeException('DemoClassroomSeeder must never run in production.');
        }

        $password = (string) env('DEMO_PASSWORD', 'password');
        $tutor = User::query()->firstOrCreate(['email' => 'demo.classroom.tutor@example.test'], ['name' => 'Demo Classroom Tutor', 'password' => Hash::make($password), 'email_verified_at' => now()]);
        $learner = User::query()->firstOrCreate(['email' => 'demo.classroom.learner@example.test'], ['name' => 'Demo Classroom Learner', 'password' => Hash::make($password), 'email_verified_at' => now()]);
        foreach ([$tutor, $learner] as $user) {
            if ($user->currentTeam === null) {
                $team = $user->ownedTeams()->create(['name' => $user->name."'s Team", 'personal_team' => true]);
                $user->forceFill(['current_team_id' => $team->id])->save();
            }
        }
        // An adult learner with a recorded birth date: unknown ages are protected as children (R-47 security S-03).
        AgeRecord::query()->firstOrCreate(['user_id' => $learner->id], ['birth_date' => now()->subYears(19)->subMonths(2)->toDateString(), 'source' => 'operator_verified', 'recorded_by_id' => $tutor->id]);
        $profile = TutorProfile::query()->firstOrCreate(['user_id' => $tutor->id], ['hourly_rate' => 250, 'status' => 'approved', 'headline' => 'Demo classroom tutor', 'timezone' => 'Africa/Johannesburg']);
        $subject = Subject::query()->firstOrCreate(['name' => 'Mathematics'], ['level' => 'high_school']);

        $session = TutorSession::query()->where('tutor_profile_id', $profile->id)->where('student_id', $learner->id)->where('notes', 'Demo classroom lesson')->first();
        $attributes = ['status' => 'confirmed', 'starts_at' => now()->addMinutes(5)->startOfMinute(), 'duration_minutes' => 60];
        if ($session) {
            $session->forceFill($attributes)->save();
            // A fresh room each run: the demo lesson's classroom (attendance, chat, board, notes) starts empty.
            Classroom::query()->where('tutor_session_id', $session->id)->delete();
        } else {
            TutorSession::query()->create([
                'tutor_profile_id' => $profile->id, 'student_id' => $learner->id, 'subject_id' => $subject->id,
                'delivery' => 'online', 'rate' => 250, 'currency' => 'ZAR', 'kind' => TutorSession::KIND_ONE_TO_ONE,
                'notes' => 'Demo classroom lesson', ...$attributes,
            ]);
        }

        $this->command?->info('Demo classroom: '.route('classroom.show', TutorSession::query()->where('notes', 'Demo classroom lesson')->value('id')));
    }
}
