<?php

namespace Database\Seeders;

use App\Domain\Academic\Contracts\LanguageProfile;
use App\Domain\Academic\Models\AcademicLevel;
use App\Domain\Marketplace\Models\TutorOffering;
use App\Domain\Marketplace\Models\TutorTeachingScope;
use App\Domain\Money\Models\Earning;
use App\Domain\Money\Models\Order;
use App\Domain\Money\Models\OrderLine;
use App\Models\Subject;
use App\Models\TutorAvailabilityWindow;
use App\Models\TutorProfile;
use App\Models\TutorSession;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Local and E2E only (tutor UI agent): one demo tutor ("Demo Tutor Workspace") with a teaching scope, teaching
 * languages, open hours, offerings, lesson requests and booked lessons in every state, and two demo sales so the
 * earnings, payouts and insights screens have something to show. Every name says Demo. The earnings rows are
 * inserted directly (no Paystack, no ledger journal): money:reconcile will rightly flag them, which is why this
 * seeder refuses production. Run after `php artisan academic:sync`:
 *   php artisan db:seed --class=DemoTutorWorkspaceSeeder
 */
class DemoTutorWorkspaceSeeder extends Seeder
{
    public function run(): void
    {
        if (app()->environment('production')) {
            throw new RuntimeException('DemoTutorWorkspaceSeeder must never run in production.');
        }

        $password = (string) env('DEMO_PASSWORD', 'password');
        $person = function (string $email, string $name) use ($password): User {
            $user = User::query()->firstOrCreate(['email' => $email], ['name' => $name, 'password' => Hash::make($password), 'email_verified_at' => now()]);
            if ($user->currentTeam === null) {
                $team = $user->ownedTeams()->create(['name' => $name."'s Team", 'personal_team' => true]);
                $user->forceFill(['current_team_id' => $team->id])->save();
            }

            return $user;
        };

        $tutor = $person('demo.workspace.tutor@example.test', 'Demo Tutor Workspace');
        $learners = [
            $person('demo.learner.one@example.test', 'Demo Learner One'),
            $person('demo.learner.two@example.test', 'Demo Learner Two'),
            $person('demo.learner.three@example.test', 'Demo Learner Three'),
        ];
        $newcomer = $person('demo.new.tutor@example.test', 'Demo New Tutor');

        $maths = Subject::query()->where('slug', 'mathematics')->first() ?? Subject::query()->firstOrCreate(['name' => 'Mathematics'], ['level' => 'high_school']);
        $profile = TutorProfile::query()->firstOrCreate(['user_id' => $tutor->id], [
            'headline' => 'Demo: Grade 10 to 12 Mathematics, CAPS',
            'bio' => 'Demo profile for local checks of the tutor workspace. Not a real tutor.',
            'hourly_rate_minor' => 25000, 'currency' => 'ZAR', 'timezone' => 'Africa/Johannesburg', 'status' => 'approved',
            'teaches_online' => true, 'public_handle' => 'demo-workspace-tutor',
        ]);
        $profile->subjects()->syncWithoutDetaching([$maths->id]);

        $g10 = AcademicLevel::query()->whereHas('curriculum', fn ($q) => $q->where('code', 'za-caps'))->where('code', 'grade-10')->first();
        $g12 = AcademicLevel::query()->whereHas('curriculum', fn ($q) => $q->where('code', 'za-caps'))->where('code', 'grade-12')->first();
        if ($profile->teachingScopes()->doesntExist()) {
            TutorTeachingScope::query()->create(['tutor_profile_id' => $profile->id, 'subject_id' => $maths->id, 'curriculum_id' => $g10?->curriculum_id, 'level_min_id' => $g10?->id, 'level_max_id' => $g12?->id]);
        }
        app(LanguageProfile::class)->syncTeachingLanguages($tutor, [['code' => 'zu', 'proficiency' => 'native'], ['code' => 'en', 'proficiency' => 'fluent']]);
        if ($profile->availabilityWindows()->doesntExist()) {
            foreach (range(1, 6) as $day) {
                TutorAvailabilityWindow::query()->create(['tutor_profile_id' => $profile->id, 'weekday' => $day, 'start_time' => '14:00', 'end_time' => '20:00']);
            }
        }

        $offer = fn (array $a) => tap(TutorOffering::query()->firstOrCreate(['tutor_profile_id' => $profile->id, 'title' => $a['title']], $a + [
            'currency' => 'ZAR', 'format' => 'online', 'capacity' => 1, 'cancellation_notice_hours' => 24, 'late_cancellation_refund_percent' => 50, 'is_active' => true,
        ]), fn ($o) => $o->subjects()->syncWithoutDetaching([$maths->id]));
        $lesson = $offer(['type' => 'one_to_one', 'title' => 'Demo one-to-one Maths lesson', 'price_minor' => 25000, 'duration_minutes' => 60]);
        $offer(['type' => 'package', 'title' => 'Demo five-lesson package', 'price_minor' => 110000, 'duration_minutes' => 60, 'package_lessons' => 5, 'package_valid_days' => 90]);
        $offer(['type' => 'group', 'title' => 'Demo Saturday revision group', 'price_minor' => 9000, 'duration_minutes' => 90, 'capacity' => 6]);

        if (TutorSession::query()->where('tutor_profile_id', $profile->id)->doesntExist()) {
            $tz = 'Africa/Johannesburg';
            $at = fn (int $days, string $time) => now($tz)->startOfDay()->addDays($days)->setTimeFromTimeString($time)->utc();
            $book = fn (User $learner, string $status, $start, array $extra = []) => TutorSession::query()->create(array_merge([
                'tutor_profile_id' => $profile->id, 'student_id' => $learner->id, 'subject_id' => $maths->id, 'status' => $status,
                'starts_at' => $start, 'duration_minutes' => 60, 'rate_minor' => 25000, 'price_minor' => 25000, 'currency' => 'ZAR',
                'tutor_offering_id' => $lesson->id, 'kind' => 'one_to_one', 'payment_status' => 'unpaid',
            ], $extra));

            $book($learners[0], 'pending', $at(2, '15:00'));
            $book($learners[1], 'pending', $at(3, '16:00'));
            $book($learners[2], 'awaiting_guardian', $at(4, '15:00'), ['guardian_status' => 'pending']);
            $book($learners[0], 'confirmed', $at(0, '18:00'), ['payment_status' => 'paid']);
            $book($learners[1], 'confirmed', $at(1, '17:00'), ['payment_status' => 'paid']);
            $book($learners[0], 'awaiting_payment', $at(5, '15:00'), ['hold_expires_at' => now()->addHours(30)]);
            $done1 = $book($learners[0], 'completed', $at(-6, '15:00'), ['payment_status' => 'paid']);
            $done2 = $book($learners[0], 'completed', $at(-13, '15:00'), ['payment_status' => 'paid']);
            $book($learners[1], 'cancelled', $at(-3, '15:00'), ['decision_reason' => 'Demo: declined, away that week.']);

            foreach ([[$done1, Earning::AVAILABLE], [$done2, Earning::PENDING]] as $i => [$session, $status]) {
                $order = Order::query()->forceCreate([
                    'uuid' => (string) Str::uuid(), 'buyer_id' => $learners[0]->id, 'status' => 'paid', 'currency' => 'ZAR',
                    'subtotal_minor' => 25000, 'total_minor' => 25000, 'paid_at' => $session->starts_at->copy()->subDay(),
                ]);
                $line = OrderLine::query()->forceCreate([
                    'order_id' => $order->id, 'purchasable_type' => 'booking', 'purchasable_id' => $session->id,
                    'title' => 'Demo: Mathematics lesson with Demo Tutor Workspace', 'seller_id' => $tutor->id,
                    'commission_category' => 'lesson', 'earning_release' => 'on_delivery', 'price_minor' => 25000, 'cash_minor' => 25000,
                    'commission_bps' => 1500, 'commission_minor' => 3750, 'seller_net_minor' => 21250,
                ]);
                Earning::query()->forceCreate([
                    'seller_id' => $tutor->id, 'order_line_id' => $line->id, 'source_key' => 'demo-sale:'.$line->id, 'kind' => Earning::KIND_SALE,
                    'gross_minor' => 25000, 'commission_minor' => 3750, 'charges_minor' => 0, 'net_minor' => 21250, 'currency' => 'ZAR',
                    'status' => $status, 'release_rule' => 'on_delivery',
                    'release_after' => $status === Earning::PENDING ? now()->addHours(20) : $session->starts_at->copy()->addDay(),
                    'available_at' => $status === Earning::AVAILABLE ? $session->starts_at->copy()->addDay() : null,
                    'created_at' => $session->starts_at, 'updated_at' => $session->starts_at,
                ]);
            }
        }

        // A second person who has only just started onboarding (a draft profile).
        TutorProfile::query()->firstOrCreate(['user_id' => $newcomer->id], [
            'headline' => 'Demo: first-year student tutoring Grade 8 Maths', 'bio' => 'Demo draft profile for the onboarding screens.',
            'hourly_rate_minor' => 15000, 'currency' => 'ZAR', 'timezone' => 'Africa/Johannesburg', 'status' => 'draft', 'teaches_online' => true,
        ]);
    }
}
