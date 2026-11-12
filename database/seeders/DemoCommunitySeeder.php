<?php

namespace Database\Seeders;

use App\Domain\Academic\Models\AcademicLevel;
use App\Domain\Academic\Models\LearnerProfile;
use App\Domain\Academic\Models\Subject;
use App\Domain\Academic\Models\SubjectOffering;
use App\Domain\Academic\Services\ReferenceDataSync;
use App\Domain\Community\Models\Post;
use App\Domain\Community\Models\Room;
use App\Domain\Community\Services\Posts;
use App\Domain\Community\Services\Rooms;
use App\Domain\Community\Services\StudyGroups;
use App\Domain\Community\Services\TutorRooms;
use App\Domain\Safety\Models\AgeRecord;
use App\Domain\Safety\Services\Guardians;
use App\Models\TutorProfile;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

/**
 * Local and E2E only: demo people named "Demo ..." in a Grade 10 CAPS Mathematics room for adults and one for
 * learners under 18, a few questions and answers, a study group and a tutor room. Every post goes through the
 * real services (gate, redaction, moderation). Refuses to run in production.
 * Run with: php artisan db:seed --class=DemoCommunitySeeder
 */
class DemoCommunitySeeder extends Seeder
{
    public function run(): void
    {
        if (app()->environment('production')) {
            throw new RuntimeException('DemoCommunitySeeder must never run in production.');
        }

        app(ReferenceDataSync::class)->run();

        $operator = $this->person('demo.moderator@example.test', 'Demo Moderator', 40);
        $operator->forceFill(['is_platform_operator' => true])->save();

        $adult = $this->person('demo.adult@example.test', 'Demo Adult Learner', 24);
        $adult2 = $this->person('demo.adult2@example.test', 'Demo Second Learner', 31);
        $teen = $this->person('demo.teen@example.test', 'Demo Teen Learner', 15);
        $teen2 = $this->person('demo.teen2@example.test', 'Demo Teen Classmate', 16);
        $guardian = $this->person('demo.guardian@example.test', 'Demo Guardian', 45);
        $tutor = $this->person('demo.roomtutor@example.test', 'Demo Room Tutor', 35);
        TutorProfile::query()->firstOrCreate(['user_id' => $tutor->id], ['hourly_rate' => 250, 'status' => 'approved', 'headline' => 'Demo tutor for room checks', 'timezone' => 'Africa/Johannesburg']);

        foreach ([$teen, $teen2] as $minor) {
            if (! app(Guardians::class)->isGuardianOf($guardian, $minor)) {
                $invite = app(Guardians::class)->invite($minor, $guardian->email, 'parent');
                parse_str((string) parse_url($invite['url'], PHP_URL_QUERY), $query);
                app(Guardians::class)->accept($invite['invitation'], $query['token'], $guardian, CarbonImmutable::now()->subYears(15)->subMonths(2));
            }
        }

        $level = AcademicLevel::query()->whereHas('curriculum', fn ($q) => $q->where('code', 'za-caps'))->where('code', 'grade-10')->firstOrFail();
        $maths = Subject::query()->where('slug', 'mathematics')->firstOrFail();
        $physics = Subject::query()->where('slug', 'physical-sciences')->firstOrFail();
        foreach ([$adult, $teen] as $learner) {
            if (! LearnerProfile::query()->where('user_id', $learner->id)->exists()) {
                $profile = new LearnerProfile(['curriculum_id' => $level->curriculum_id, 'academic_level_id' => $level->id, 'onboarded_at' => now()]);
                $profile->forceFill(['user_id' => $learner->id])->save();
                foreach ([$maths, $physics] as $s) {
                    $profile->subjects()->attach($s->id, ['curriculum_subject_level_id' => SubjectOffering::query()->where('academic_level_id', $level->id)->where('subject_id', $s->id)->value('id')]);
                }
            }
        }

        TutorProfile::query()->where('user_id', $tutor->id)->first()?->subjects()->syncWithoutDetaching([$maths->id]);
        $offering = SubjectOffering::query()->where('academic_level_id', $level->id)->where('subject_id', $maths->id)->firstOrFail();
        $adultRoom = app(Rooms::class)->subjectRoom($offering, Room::ADULT);
        $teenRoom = app(Rooms::class)->subjectRoom($offering, Room::UNDER_18);

        if ($adultRoom->posts()->doesntExist()) {
            $posts = app(Posts::class);
            $q1 = $posts->create($adult, $adultRoom, Post::QUESTION, ['title' => 'Demo: how do I factorise x² - 9?', 'body' => "I tried taking out a common factor but there isn't one.", 'original_locale' => 'en']);
            $a1 = $posts->create($adult2, $adultRoom, Post::ANSWER, ['body' => 'It is a difference of two squares: a² - b² = (a - b)(a + b), so x² - 9 = (x - 3)(x + 3).', 'original_locale' => 'en'], $q1);
            $posts->accept($adult, $a1);
            $posts->create($adult2, $adultRoom, Post::QUESTION, ['title' => 'Demo: when is the discriminant negative?', 'body' => 'For quadratic equations, what does b² - 4ac < 0 tell me about the graph?', 'original_locale' => 'en']);
            $posts->create($adult, $adultRoom, Post::TECHNIQUE, ['title' => 'Demo: past papers backwards', 'body' => 'I start with the last question of a past paper, because those carry the most marks and show where I am weakest.', 'original_locale' => 'en']);
            $posts->create($adult2, $adultRoom, Post::QUESTION, ['title' => 'Demo: Ngicela usizo ngama-exponents', 'body' => 'Ngiyazama ukuqonda ukuthi kungani u-x⁰ = 1.', 'original_locale' => 'zu']);

            $group = app(StudyGroups::class)->create($adult, $adultRoom, ['name' => 'Demo: Paper 1 revision', 'topic' => 'Algebra and equations', 'exam_focus' => 'November Paper 1', 'schedule_note' => 'Tuesdays at 18:00', 'size_cap' => 6, 'join_rule' => 'open']);
            app(StudyGroups::class)->join($adult2, $group, null);
            app(StudyGroups::class)->schedule($adult, $adultRoom, $group, ['title' => 'Factorising practice', 'starts_at' => now('Africa/Johannesburg')->addDays(2)->setTime(18, 0)->format('Y-m-d H:i'), 'timezone' => 'Africa/Johannesburg', 'duration_minutes' => 60, 'where_note' => 'Online']);
            app(StudyGroups::class)->addRevisionItem($adult, $group, 'Difference of two squares', null);
            app(StudyGroups::class)->addRevisionItem($adult2, $group, 'Trinomials', null);
            app(StudyGroups::class)->addTask($adult, $group, 'Do Paper 1 2023, question 1', 'Write out every step.', now()->addWeek()->toDateString(), [$adult->id, $adult2->id]);

            app(TutorRooms::class)->create($tutor, ['curriculum_subject_level_id' => $offering->id, 'audience' => Room::ADULT, 'title' => 'Demo: Saturday Paper 1 workshops', 'description' => 'Demo tutor room for local checks.', 'language' => 'en', 'join_rule' => Room::OPEN, 'capacity' => 12, 'price_minor' => 12000]);
        }

        if ($teenRoom->posts()->doesntExist()) {
            $q = app(Posts::class)->create($teen, $teenRoom, Post::QUESTION, ['title' => 'Demo: what is the gradient of y = 3x + 2?', 'body' => 'Is it 3 or 2? Message me on 082 123 4567 if you can help.', 'original_locale' => 'en']);
            app(Posts::class)->create($teen2, $teenRoom, Post::ANSWER, ['body' => 'It is 3: in y = mx + c, m is the gradient and c is where the line crosses the y-axis.', 'original_locale' => 'en'], $q);
        }

        $this->command?->info('Demo community ready. Sign in as demo.adult@example.test, demo.teen@example.test or demo.moderator@example.test (password from DEMO_PASSWORD, default "password").');
    }

    private function person(string $email, string $name, int $age): User
    {
        $user = User::query()->firstOrCreate(['email' => $email], ['name' => $name, 'password' => Hash::make((string) env('DEMO_PASSWORD', 'password')), 'email_verified_at' => now()]);
        if ($user->currentTeam === null) {
            $team = $user->ownedTeams()->create(['name' => $name."'s Team", 'personal_team' => true]);
            $user->forceFill(['current_team_id' => $team->id])->save();
        }
        AgeRecord::query()->firstOrCreate(['user_id' => $user->id], ['birth_date' => now()->subYears($age)->subMonths(2)->toDateString(), 'source' => 'operator_verified', 'recorded_by_id' => $user->id]);

        return $user;
    }
}
