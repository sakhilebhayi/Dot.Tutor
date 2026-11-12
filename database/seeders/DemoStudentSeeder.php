<?php

namespace Database\Seeders;

use App\Domain\Academic\Contracts\LanguageProfile;
use App\Domain\Academic\Enums\LanguageRole;
use App\Domain\Academic\Models\AcademicLevel;
use App\Domain\Academic\Models\Country;
use App\Domain\Academic\Services\LearnerProfiles;
use App\Domain\Academic\Services\ReferenceDataSync;
use App\Domain\Marketplace\Models\TutorOffering;
use App\Domain\Marketplace\Models\TutorTeachingScope;
use App\Domain\Marketplace\Services\BookingFlow;
use App\Models\Subject;
use App\Models\TutorAvailabilityWindow;
use App\Models\TutorProfile;
use App\Models\TutorSession;
use App\Models\User;
use App\Services\Scheduling\SlotFinder;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

/**
 * Local checks of the learner screens only (student UI, D-1121): every record is named "Demo" and uses example.test
 * addresses. Refuses to run in production. Run with: php artisan db:seed --class=DemoStudentSeeder
 *
 * Learners: demo.grade3@example.test (Grade 3, age 8: young step, limited until a guardian links),
 * demo.matric@example.test (Grade 12, age 18), demo.university@example.test (first-year, age 20).
 * Tutor: demo.thandeka@example.test (CAPS Mathematics Grades 4 to 12, teaches in isiZulu and English).
 */
class DemoStudentSeeder extends Seeder
{
    public function run(): void
    {
        if (app()->environment('production')) {
            throw new RuntimeException('DemoStudentSeeder must never run in production.');
        }

        app(ReferenceDataSync::class)->run();
        $this->call(DemoLearningSeeder::class);

        $maths = Subject::query()->where('slug', 'mathematics')->firstOrFail();
        $caps = fn (string $code) => AcademicLevel::query()->where('code', $code)->whereHas('curriculum', fn ($q) => $q->where('code', 'za-caps'))->firstOrFail();

        $tutorUser = $this->person('demo.thandeka@example.test', 'Demo Thandeka Mokoena');
        $profile = TutorProfile::query()->firstOrCreate(['user_id' => $tutorUser->id], [
            'hourly_rate' => 250, 'hourly_rate_minor' => 25000, 'currency' => 'ZAR', 'status' => 'approved', 'reviewed_at' => now(),
            'headline' => 'Demo: CAPS Mathematics, Grades 4 to 12', 'bio' => "Demo profile for local checks.\nPatient, exam-focused lessons with worked examples.",
            'teaching_approach' => 'We start from what you already know and build up with short practice.', 'years_experience' => 6,
            'teaches_online' => true, 'teaches_in_person' => false, 'timezone' => 'Africa/Johannesburg',
        ]);
        $profile->subjects()->syncWithoutDetaching([$maths->id]);
        TutorTeachingScope::query()->firstOrCreate(['tutor_profile_id' => $profile->id, 'subject_id' => $maths->id], [
            'curriculum_id' => $caps('grade-4')->curriculum_id, 'level_min_id' => $caps('grade-4')->id, 'level_max_id' => $caps('grade-12')->id,
        ]);
        app(LanguageProfile::class)->syncTeachingLanguages($tutorUser, [['code' => 'zu', 'proficiency' => 'native'], ['code' => 'en', 'proficiency' => 'fluent']]);
        foreach (range(1, 7) as $weekday) {
            TutorAvailabilityWindow::query()->firstOrCreate(['tutor_profile_id' => $profile->id, 'weekday' => $weekday], ['start_time' => '14:00', 'end_time' => '19:00']);
        }
        $lesson = TutorOffering::query()->firstOrCreate(['tutor_profile_id' => $profile->id, 'title' => 'Demo: one Maths lesson'], [
            'type' => 'one_to_one', 'description' => 'One hour, online, any topic in the CAPS Mathematics syllabus.', 'price_minor' => 25000, 'currency' => 'ZAR',
            'duration_minutes' => 60, 'format' => 'online', 'cancellation_notice_hours' => 24, 'late_cancellation_refund_percent' => 50, 'is_active' => true,
        ]);
        $lesson->subjects()->syncWithoutDetaching([$maths->id]);
        $package = TutorOffering::query()->firstOrCreate(['tutor_profile_id' => $profile->id, 'title' => 'Demo: four exam-prep lessons'], [
            'type' => 'package', 'description' => 'Four lessons before your exams.', 'price_minor' => 90000, 'currency' => 'ZAR', 'duration_minutes' => 60,
            'format' => 'online', 'package_lessons' => 4, 'package_valid_days' => 60, 'cancellation_notice_hours' => 24, 'late_cancellation_refund_percent' => 0, 'is_active' => true,
        ]);
        $package->subjects()->syncWithoutDetaching([$maths->id]);

        $za = Country::query()->where('code', 'ZA')->value('id');
        $grade3 = $this->learner('demo.grade3@example.test', 'Demo Lerato Grade 3', $za, $caps('grade-3')->id, 8, ['zu']);
        $matric = $this->learner('demo.matric@example.test', 'Demo Sipho Matric', $za, $caps('grade-12')->id, 18, ['zu', 'en']);
        $uni = $this->learner('demo.university@example.test', 'Demo Naledi University', $za,
            AcademicLevel::query()->where('code', 'undergraduate-1')->value('id'), 20, ['en']);

        // One requested lesson for the matric learner so the home and lessons screens show a real booking.
        if (TutorSession::query()->where('student_id', $matric->id)->doesntExist()) {
            $slot = collect(app(SlotFinder::class)->forTutor($profile, 60))->flatMap(fn ($d) => $d['slots'])->get(3);
            if ($slot) {
                app(BookingFlow::class)->requestBooking($matric, $lesson, ['subject_id' => $maths->id, 'starts_at' => $slot->utc()->toIso8601String(), 'notes' => 'Demo: functions and graphs']);
            }
        }
        unset($grade3, $uni);
    }

    private function person(string $email, string $name): User
    {
        $user = User::query()->firstOrCreate(['email' => $email], [
            'name' => $name, 'password' => Hash::make((string) env('DEMO_PASSWORD', 'password')), 'email_verified_at' => now(),
        ]);
        if ($user->currentTeam === null) {
            $team = $user->ownedTeams()->create(['name' => $name."'s Team", 'personal_team' => true]);
            $user->forceFill(['current_team_id' => $team->id])->save();
        }

        return $user;
    }

    /** @param list<string> $teaching */
    private function learner(string $email, string $name, int $countryId, int $levelId, int $age, array $teaching): User
    {
        $user = $this->person($email, $name);
        $born = now()->subYears($age)->subMonths(2);
        $subjects = Subject::query()->where('slug', 'mathematics')->pluck('id')->all();
        app(LearnerProfiles::class)->save($user, [
            'country_id' => $countryId, 'academic_level_id' => $levelId, 'subjects' => $subjects,
            'birth_year' => $born->year, 'birth_month' => $born->month,
            'teaching' => $teaching, 'understands' => ['en'], 'learning' => [], 'bilingual_ok' => '1',
        ]);
        app(LanguageProfile::class)->sync($user, LanguageRole::Understands, ['en']);

        return $user;
    }
}
