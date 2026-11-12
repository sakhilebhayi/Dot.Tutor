<?php

namespace Database\Seeders;

use App\Domain\Academic\Services\ReferenceDataSync;
use App\Domain\Community\Models\Post;
use App\Domain\Community\Models\Room;
use App\Domain\Community\Services\Rooms;
use App\Domain\Marketplace\Models\TutorQualification;
use App\Domain\Money\Services\FinanceIssues;
use App\Domain\Ops\Access\RoleAdministration;
use App\Domain\Safety\Contracts\GuardianApprovals;
use App\Domain\Safety\Contracts\ReportSink;
use App\Domain\Safety\Enums\ReportCategory;
use App\Domain\Safety\Models\AgeRecord;
use App\Domain\Safety\Services\Guardians;
use App\Domain\Safety\Services\Messenger;
use App\Models\SessionRating;
use App\Models\Subject;
use App\Models\TutorProfile;
use App\Models\TutorSession;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

/**
 * Local and E2E only: people named "Demo ..." so the operations command centre and the guardian pages can be
 * looked at with rows in them. Operators for each role, tutors with lessons over recent weeks (some held, one
 * disputed, reviews from the learners who took them), a child with a guardian and a purchase waiting for approval,
 * a child-safety report (which opens its critical incident through the real report sink), a qualification sent for
 * review, a held room post, a finance exception and a support thread. Nothing here is presented as real: every
 * name starts with "Demo". Passwords come from DEMO_PASSWORD (default "password"). Refuses to run in production.
 * Run with: php artisan db:seed --class=DemoOpsSeeder
 */
class DemoOpsSeeder extends Seeder
{
    public function run(): void
    {
        if (app()->environment('production')) {
            throw new RuntimeException('DemoOpsSeeder must never run in production.');
        }

        app(ReferenceDataSync::class)->run();

        $admin = $this->person('demo.ops.admin@example.test', 'Demo Ops Admin', 41);
        app(RoleAdministration::class)->setRoles(null, $admin, ['admin']);
        foreach (['finance' => 'Demo Finance', 'safeguarding_officer' => 'Demo Safeguarding Officer', 'content_moderator' => 'Demo Content Moderator',
            'verification_reviewer' => 'Demo Verification Reviewer', 'support_agent' => 'Demo Support Agent', 'academic_editor' => 'Demo Academic Editor'] as $role => $name) {
            app(RoleAdministration::class)->setRoles($admin, $this->person('demo.ops.'.$role.'@example.test', $name, 35), [$role]);
        }

        $maths = Subject::query()->where('slug', 'mathematics')->firstOrFail();
        $tutors = collect(['Demo Tutor Ayanda', 'Demo Tutor Pieter', 'Demo Tutor Refilwe'])->map(function (string $name, int $i) use ($maths) {
            $user = $this->person('demo.ops.tutor'.$i.'@example.test', $name, 28 + $i);
            $profile = TutorProfile::query()->firstOrCreate(['user_id' => $user->id], ['hourly_rate' => 250 + 50 * $i, 'status' => 'approved', 'headline' => 'Demo tutor', 'timezone' => 'Africa/Johannesburg']);
            $profile->subjects()->syncWithoutDetaching([$maths->id]);

            return $profile;
        });
        $applicant = $this->person('demo.ops.applicant@example.test', 'Demo Tutor Applicant', 22);
        TutorProfile::query()->firstOrCreate(['user_id' => $applicant->id], ['hourly_rate' => 150, 'status' => 'pending', 'headline' => 'Demo applicant: second-year BSc', 'timezone' => 'Africa/Johannesburg']);

        $learners = collect(range(1, 8))->map(fn (int $i) => $this->person('demo.ops.learner'.$i.'@example.test', 'Demo Learner '.$i, 19 + $i));

        if (TutorSession::query()->whereIn('tutor_profile_id', $tutors->pluck('id'))->doesntExist()) {
            foreach (range(1, 26) as $n) {
                $tutor = $tutors[$n % 3];
                $learner = $learners[$n % 8];
                $start = CarbonImmutable::now()->subDays(80 - $n * 3)->setTime(15, 0);
                $session = TutorSession::query()->forceCreate([
                    'tutor_profile_id' => $tutor->id, 'student_id' => $learner->id, 'subject_id' => $maths->id,
                    'status' => $start->isPast() ? ($n % 9 === 0 ? 'no_show' : 'completed') : 'confirmed',
                    'starts_at' => $start, 'duration_minutes' => 60, 'rate' => $tutor->hourly_rate, 'currency' => 'ZAR',
                    'created_at' => $start->subDays(3), 'updated_at' => $start,
                ]);
                if ($session->status === 'completed' && $n % 2 === 0) {
                    SessionRating::query()->forceCreate(['session_id' => $session->id, 'rated_by' => $learner->id, 'rating' => [5, 4, 5, 3, 5][$n % 5], 'review' => 'Demo review.', 'status' => 'published']);
                }
            }
            TutorSession::query()->forceCreate([
                'tutor_profile_id' => $tutors[0]->id, 'student_id' => $learners[0]->id, 'subject_id' => $maths->id, 'status' => 'disputed',
                'starts_at' => now()->subDays(2), 'duration_minutes' => 60, 'rate' => 250, 'currency' => 'ZAR', 'price_minor' => 25000,
                'decision_reason' => 'Demo: the learner says the tutor left after 20 minutes.',
            ]);
        }

        TutorQualification::query()->firstOrCreate(['tutor_profile_id' => $tutors[1]->id, 'title' => 'Demo BEd (Senior and FET Phase)'],
            ['kind' => 'teaching_qualification', 'institution' => 'University of the Western Cape', 'year_awarded' => 2019, 'status' => 'submitted', 'submitted_at' => now()->subDay()]);

        $child = $this->person('demo.ops.child@example.test', 'Demo Child Lindiwe', 10);
        $guardian = $this->person('demo.ops.guardian@example.test', 'Demo Guardian Thabo', 44);
        if (! app(Guardians::class)->isGuardianOf($guardian, $child)) {
            $invite = app(Guardians::class)->invite($child, $guardian->email, 'parent');
            parse_str((string) parse_url($invite['url'], PHP_URL_QUERY), $query);
            app(Guardians::class)->accept($invite['invitation'], $query['token'], $guardian, CarbonImmutable::now()->subYears(10)->subMonths(2));
        }
        if (\App\Domain\Safety\Models\GuardianApproval::query()->where('learner_id', $child->id)->doesntExist()) {
            app(GuardianApprovals::class)->request($child, 'purchase', $child, ['summary' => 'Demo: Grade 4 Fractions course by Demo Tutor Ayanda', 'amount_minor' => 34900, 'currency' => 'ZAR']);
        }

        if (\App\Domain\Safety\Models\Report::query()->doesntExist()) {
            app(ReportSink::class)->report($child, $tutors[2]->user, ReportCategory::InappropriateContactWithMinor, 'Demo: asked me for my WhatsApp number after the lesson.');
            app(ReportSink::class)->report($learners[2], $learners[3], ReportCategory::Spam, 'Demo: posting the same advert in every room.');
        }

        $room = app(Rooms::class)->subjectRoom(\App\Domain\Academic\Models\SubjectOffering::query()
            ->whereHas('level', fn ($q) => $q->where('code', 'grade-4'))->where('subject_id', $maths->id)->firstOrFail(), Room::UNDER_18);
        Post::query()->firstOrCreate(['room_id' => $room->id, 'author_id' => $child->id, 'title' => 'Demo: why is a half bigger than a third?'],
            ['kind' => 'question', 'body' => 'If 3 is bigger than 2, why is a third smaller?', 'status' => Post::HELD, 'held_reason' => 'pre_moderation']);

        app(FinanceIssues::class)->raise('unmatched_payment', 'demo:unmatched:1', 'Demo: Paystack charge DEMO-REF-1 matches no Dot.Tutor order', ['amount_minor' => 25000, 'currency' => 'ZAR', 'severity' => 'critical']);

        if (\App\Domain\Safety\Models\Conversation::query()->where('context_type', 'support')->doesntExist()) {
            app(Messenger::class)->start($learners[4], 'support', null, [], 'Demo: I was charged but my lesson still says unpaid.', 'Demo: payment not showing');
        }
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
