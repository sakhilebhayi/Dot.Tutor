<?php

namespace Tests\Feature\Landing;

use App\Domain\Ops\Access\OpsRole;
use App\Domain\Ops\Access\RoleAdministration;
use App\Models\Team;
use App\Models\TutorProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Safety\BuildsSafetyState;
use Tests\TestCase;

/**
 * R-50, the arrival: what each role sees first after InfoDot hands them to Dot.Tutor. Every role home carries the
 * original logo, a welcome from the person's own record, and exactly one primary action that leads to a page which
 * answers 200 for that role. Nothing on the arrival is a made-up figure, and no other person is named on it.
 */
class ArrivalLandingTest extends TestCase
{
    use BuildsSafetyState;
    use RefreshDatabase;

    /** SHA-256 of public/dot_tutor.png, the 1181 x 886 original. The landing shows this file and never a redrawn one. */
    private const LOGO_SHA256 = 'd8eb63072514173b3e81886070d23f63b8d692d00213f97f8dd0deb6f15c407a';

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
    }

    /** The arrival's own markup, so assertions never read the working lists below it. */
    private function arrival(TestResponse $page): string
    {
        $html = $page->getContent();
        $this->assertSame(1, substr_count($html, 'class="td-arrival"'), 'one arrival on the page');

        return Str::betweenFirst($html, '<section class="td-arrival"', '</section>');
    }

    /** Exactly one primary action on the whole page; returns where it leads. */
    private function primaryHref(TestResponse $page): string
    {
        $html = $page->getContent();
        $this->assertSame(1, preg_match_all('/\sdata-primary-action[=\s>]/', $html), 'exactly one primary action');
        $this->assertSame(1, substr_count($html, 'td-btn--primary td-btn--lg'), 'only the arrival has the large primary button');
        $this->assertSame(1, preg_match('#<a href="([^"]+)"[^>]*data-primary-action#', $html, $m), 'the primary action is a link');

        return html_entity_decode($m[1]);
    }

    private function assertComposition(string $arrival): void
    {
        $this->assertStringContainsString('src="'.asset('dot_tutor.png').'"', $arrival);
        $this->assertStringContainsString('alt="Dot.Tutor"', $arrival);
        $this->assertSame(1, substr_count($arrival, '<h1'), 'the headline is the one h1');
        $this->assertStringContainsString('href="https://infodot.co.za/home">InfoDot</a>', $arrival);
        $this->assertStringContainsString('href="https://infodot.co.za/platforms">Platforms</a>', $arrival);
    }

    private function words(string $arrival): string
    {
        return trim((string) preg_replace('/\s+/', ' ', html_entity_decode(strip_tags((string) preg_replace('/<img[^>]*>/', '', $arrival)))));
    }

    public function test_the_logo_is_the_original_file(): void
    {
        $path = public_path('dot_tutor.png');
        $this->assertFileExists($path);
        $this->assertSame(self::LOGO_SHA256, hash_file('sha256', $path));
        $this->assertSame([1181, 886], array_slice((array) getimagesize($path), 0, 2));
    }

    public function test_a_new_member_with_nothing_is_asked_for_the_short_profile_first(): void
    {
        // The live setting: someone whose age is not known yet is protected as a child until they say (D-1502).
        config(['safety.unknown_age_policy' => 'ask']);
        $member = $this->makeUser('Nomsa Dlamini');

        $page = $this->actingAs($member)->get(route('learn.home'))->assertOk();
        $arrival = $this->arrival($page);
        $this->assertComposition($arrival);
        $words = $this->words($arrival);
        $this->assertStringContainsString('Welcome, Nomsa.', $words);
        $this->assertStringNotContainsString('Team', $words, 'a personal team is not a company');
        $this->assertDoesNotMatchRegularExpression('/\d/', $words, 'no figure on an arrival with no records behind it');

        $href = $this->primaryHref($page);
        $this->assertSame(route('learn.onboarding'), $href);
        $this->actingAs($member)->get($href)->assertOk();
    }

    public function test_an_adult_learner_with_nothing_booked_is_sent_to_find_a_tutor(): void
    {
        $learner = $this->adult('Pieter Botha');

        $page = $this->actingAs($learner)->get(route('learn.home'))->assertOk();
        $words = $this->words($this->arrival($page));
        $this->assertStringContainsString('Nothing booked yet', $words);
        $this->assertStringContainsString('You have no lessons, set work or courses in progress.', $words);
        $this->assertDoesNotMatchRegularExpression('/\d/', $words);

        $href = $this->primaryHref($page);
        $this->assertSame(route('marketplace.find'), $href);
        $this->actingAs($learner)->get($href)->assertOk();
    }

    public function test_a_learner_with_a_lesson_continues_it_and_the_tutor_is_not_named_on_the_arrival(): void
    {
        $learner = $this->adult('Sipho Khumalo');
        $subject = $this->makeSubject('Physical Sciences');
        $profile = $this->makeTutor([], $subject, $this->makeUser('Zanele Ndlovu'));
        $lesson = $this->makeSession($profile, $learner, $subject, ['status' => 'confirmed', 'starts_at' => now()->addDay()->setTime(15, 0)]);

        $page = $this->actingAs($learner)->get(route('learn.home'))->assertOk();
        $arrival = $this->arrival($page);
        $this->assertComposition($arrival);
        $words = $this->words($arrival);
        $this->assertStringContainsString('Welcome, Sipho.', $words);
        $this->assertStringContainsString('Your next lesson', $words);
        $this->assertStringContainsString('Physical Sciences', $words);
        $this->assertStringNotContainsString('Zanele', $words);
        $this->assertStringNotContainsString('Ndlovu', $words);

        $href = $this->primaryHref($page);
        $this->assertSame(route('learn.lessons.show', $lesson), $href);
        $this->actingAs($learner)->get($href)->assertOk();
    }

    public function test_the_company_is_named_only_when_the_team_is_one(): void
    {
        $learner = $this->adult('Lerato Mokoena');
        $team = Team::factory()->create(['user_id' => $learner->id, 'name' => 'Khanya Study Centre', 'personal_team' => false]);
        $learner->forceFill(['current_team_id' => $team->id])->save();

        $words = $this->words($this->arrival($this->actingAs($learner->fresh())->get(route('learn.home'))->assertOk()));
        $this->assertStringContainsString('Welcome, Lerato, from Khanya Study Centre.', $words);
    }

    public function test_a_tutor_with_requests_answers_them_and_the_count_is_the_real_one(): void
    {
        $subject = $this->makeSubject('Accounting');
        $profile = $this->makeTutor([], $subject, $this->makeUser('Thandi Mokoena'));
        $this->makeSession($profile, $this->adult('Kabelo Sithole'), $subject);
        $this->makeSession($profile, $this->adult('Aisha Patel'), $subject, ['starts_at' => now()->addDays(3)->setTime(11, 0)]);

        $page = $this->actingAs($profile->user)->get(route('teach.home'))->assertOk();
        $arrival = $this->arrival($page);
        $this->assertComposition($arrival);
        $words = $this->words($arrival);
        $this->assertStringContainsString('Welcome, Thandi.', $words);
        $this->assertStringContainsString('Waiting for your answer 2 requests.', $words);
        $this->assertSame(['2'], array_values(array_unique(preg_match_all('/\d+/', $words, $m) ? $m[0] : [])), 'the only figure is the seeded request count');
        $this->assertStringNotContainsString('Kabelo', $words);
        $this->assertStringNotContainsString('Aisha', $words);

        $href = $this->primaryHref($page);
        $this->assertSame(route('teach.bookings'), $href);
        $this->actingAs($profile->user)->get($href)->assertOk();
    }

    public function test_a_tutor_with_a_draft_profile_continues_it(): void
    {
        $user = $this->makeUser('Bongani Zulu');
        TutorProfile::create(['user_id' => $user->id, 'hourly_rate' => 250, 'status' => 'draft']);

        $page = $this->actingAs($user)->get(route('teach.home'))->assertOk();
        $this->assertStringContainsString('Still to do:', $this->words($this->arrival($page)));

        $href = $this->primaryHref($page);
        $this->assertSame(route('teach.profile'), $href);
        $this->actingAs($user)->get($href)->assertOk();
    }

    public function test_an_approved_tutor_with_nothing_listed_sets_up_an_offering(): void
    {
        $profile = $this->makeTutor([], null, $this->makeUser('Naledi Dube'));

        $page = $this->actingAs($profile->user)->get(route('teach.home'))->assertOk();
        $words = $this->words($this->arrival($page));
        $this->assertStringContainsString('No offering yet', $words);
        $this->assertDoesNotMatchRegularExpression('/\d/', $words);

        $href = $this->primaryHref($page);
        $this->assertSame(route('teach.offerings.create'), $href);
        $this->actingAs($profile->user)->get($href)->assertOk();
    }

    public function test_an_operator_opens_the_queue_with_work_in_it_or_is_told_nothing_waits(): void
    {
        $admin = User::factory()->create(['name' => 'Refilwe Nkosi']);
        app(RoleAdministration::class)->setRoles(null, $admin, [OpsRole::Admin->value]);
        $admin = $admin->fresh();

        $page = $this->actingAs($admin)->get(route('ops.home'))->assertOk();
        $arrival = $this->arrival($page);
        $this->assertComposition($arrival);
        $words = $this->words($arrival);
        $this->assertStringContainsString('Welcome, Refilwe.', $words);
        $this->assertStringContainsString('Nothing is waiting', $words);
        $this->actingAs($admin)->get($this->primaryHref($page))->assertOk();

        // One tutor application arrives: the arrival names that queue with its real count.
        $applicant = $this->makeUser('Applicant Person');
        TutorProfile::create(['user_id' => $applicant->id, 'hourly_rate' => 250, 'status' => 'pending']);

        $page = $this->actingAs($admin)->get(route('ops.home'))->assertOk();
        $words = $this->words($this->arrival($page));
        $this->assertStringContainsString('Waiting for you 1 in tutor applications waiting.', $words);
        $this->assertStringNotContainsString('Applicant', $words);
        $href = $this->primaryHref($page);
        $this->assertSame(route('ops.verification'), $href);
        $this->actingAs($admin)->get($href)->assertOk();
    }

    public function test_a_guardian_opens_their_childs_page_and_the_child_is_not_named_on_the_arrival(): void
    {
        $child = $this->child('Lindiwe');
        $guardian = $this->adult('Thabo Molefe');
        $this->linkGuardian($child, $guardian, false, 9);

        $page = $this->actingAs($guardian)->get(route('guardian.home'))->assertOk();
        $arrival = $this->arrival($page);
        $this->assertComposition($arrival);
        $words = $this->words($arrival);
        $this->assertStringContainsString('Welcome, Thabo.', $words);
        $this->assertStringContainsString('Nothing is waiting', $words);
        $this->assertStringNotContainsString('Lindiwe', $words);
        $this->assertDoesNotMatchRegularExpression('/\d\d?\s/', Str::after($words, 'Welcome'));

        $href = $this->primaryHref($page);
        $this->assertSame(route('guardian.child', $child->id), $href);
        $this->actingAs($guardian)->get($href)->assertOk();
    }

    public function test_the_older_overview_points_each_person_to_their_own_home(): void
    {
        $learner = $this->adult('Pieter Botha');
        $page = $this->actingAs($learner)->get(route('dashboard'))->assertOk();
        $this->assertComposition($this->arrival($page));
        $href = $this->primaryHref($page);
        $this->assertSame(route('marketplace.find'), $href);
        $this->actingAs($learner)->get($href)->assertOk();

        $profile = $this->makeTutor([], null, $this->makeUser('Naledi Dube'));
        $page = $this->actingAs($profile->user)->get(route('dashboard'))->assertOk();
        $href = $this->primaryHref($page);
        $this->assertSame(route('teach.home', absolute: false), $href);
        $this->actingAs($profile->user)->get($href)->assertOk();
    }

    public function test_the_platform_list_holds_only_current_platforms(): void
    {
        $current = ['agents', 'analytics', 'auction', 'central', 'charts', 'design', 'docs', 'dopemine', 'ehail', 'emall', 'engage', 'files', 'finance', 'forms', 'hr', 'memory', 'mines', 'notify', 'press', 'projects', 'pulse', 'sheet', 'tasks', 'tutor', 'infodot'];

        $this->assertSame([], array_values(array_diff(array_keys(config('ecosystem.platforms')), $current)));
        $this->assertSame('Dot.Tutor', config('ecosystem.platforms.tutor.name'));
    }
}
