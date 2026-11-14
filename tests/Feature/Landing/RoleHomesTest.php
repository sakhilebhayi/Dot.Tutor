<?php

namespace Tests\Feature\Landing;

use App\Domain\Ops\Access\OpsRole;
use App\Domain\Ops\Access\RoleAdministration;
use App\Domain\Ops\Insights\Overview;
use App\Domain\Ops\Insights\Queues;
use App\Domain\Safety\Contracts\ReportSink;
use App\Domain\Safety\Enums\ReportCategory;
use App\Models\TutorProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Tests\Feature\Acceptance\BuildsJourneys;
use Tests\Feature\Safety\BuildsSafetyState;
use Tests\TestCase;

/**
 * R-51: the four role homes (learner, tutor, guardian, operator) on one head, row and fact layer. The figures are the
 * ones the controllers already compute, every list is the signed-in person's own, and the operator home runs no query
 * of its own.
 */
class RoleHomesTest extends TestCase
{
    use BuildsJourneys;
    use BuildsSafetyState;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        $this->setUpWorld();
    }

    private function admin(): User
    {
        $admin = User::factory()->create(['name' => 'Home Admin']);
        app(RoleAdministration::class)->setRoles(null, $admin, [OpsRole::Admin->value]);

        return $admin->fresh();
    }

    public function test_the_learner_home_lists_only_their_own_records_in_one_coming_up_list(): void
    {
        $subject = $this->makeSubject('Geography');
        $tutor = $this->makeTutor([], $subject, $this->makeUser('Gerrit Tutor'));
        $mine = $this->adult('Mine Learner');
        $other = $this->adult('Other Learner');
        $this->makeSession($tutor, $mine, $subject, ['status' => 'confirmed', 'starts_at' => now()->addDay()->setTime(15, 0)]);
        $this->makeSession($tutor, $other, $subject, ['status' => 'confirmed', 'starts_at' => now()->addDays(2)->setTime(10, 0)]);

        $html = $this->actingAs($mine)->get(route('learn.home'))->assertOk()->getContent();

        $this->assertSame(1, substr_count($html, 'id="coming-title"'));
        $this->assertSame(1, preg_match_all('/class="hm-row"/', $html), 'one row: the learner\'s own lesson');
        $this->assertStringContainsString('Geography with Gerrit Tutor', $html);
        $this->assertStringContainsString('1 lesson coming up', trim(preg_replace('/\s+/', ' ', strip_tags($html))));
        $this->assertStringNotContainsString('Other Learner', $html);
        $this->assertStringContainsString('The next thing', $html, 'the standard step shows the fact sheet beside the list');
    }

    public function test_the_learner_home_says_so_when_nothing_is_booked(): void
    {
        $html = $this->actingAs($this->adult('Empty Learner'))->get(route('learn.home'))->assertOk()->getContent();

        $this->assertStringContainsString('No lessons booked.', $html);
        $this->assertStringContainsString('hm-empty', $html);
        $this->assertSame(0, preg_match_all('/class="hm-row"/', $html));
    }

    public function test_the_young_step_is_one_column_with_the_fact_sheet_dropped(): void
    {
        $child = $this->person('Young Lerato');
        $this->learnerProfile($child, 8, 'za-caps', 'grade-3', ['zu']);

        $html = $this->actingAs($child)->get(route('learn.home'))->assertOk()->getContent();

        $this->assertStringContainsString('data-td-step="young"', $html);
        $this->assertStringContainsString('class="hm-one"', $html);
        $this->assertStringNotContainsString('class="hm-split"', $html);
        $this->assertStringNotContainsString('The next thing', $html);
        $this->assertStringContainsString('Hi Young. ', $html);
    }

    public function test_a_tutor_home_shows_only_their_own_requests_and_the_money_is_theirs(): void
    {
        $subject = $this->makeSubject('Economics');
        $mine = $this->makeTutor([], $subject, $this->makeUser('My Tutor'));
        $theirs = $this->makeTutor([], $subject, $this->makeUser('Their Tutor'));
        $this->makeSession($mine, $this->adult('Mine Student'), $subject);
        $this->makeSession($theirs, $this->adult('Their Student'), $subject, ['starts_at' => now()->addDays(3)->setTime(11, 0)]);

        $html = $this->actingAs($mine->user)->get(route('teach.home'))->assertOk()->getContent();

        $this->assertStringContainsString('Mine Student', $html);
        $this->assertStringNotContainsString('Their Student', $html);
        $this->assertSame(1, preg_match_all('/id="request-\d+"/', $html));
        $this->assertStringContainsString('1 booking request is waiting for your answer.', $html);
    }

    public function test_an_unapproved_tutor_sees_the_steps_still_to_do_and_no_lists(): void
    {
        $user = $this->makeUser('Draft Tutor');
        TutorProfile::create(['user_id' => $user->id, 'hourly_rate' => 250, 'status' => 'draft']);

        $html = $this->actingAs($user)->get(route('teach.home'))->assertOk()->getContent();

        $this->assertStringContainsString('Still to do', $html);
        $this->assertStringNotContainsString('Needs your answer', $html);
        $this->assertStringNotContainsString('Next payout run', $html);
    }

    public function test_an_urgent_report_replaces_the_guardians_headline_and_leads_the_page(): void
    {
        $child = $this->child('Palesa Child');
        $guardian = $this->adult('Pule Guardian');
        $this->linkGuardian($child, $guardian, false, 9);
        app(ReportSink::class)->report($child, $this->adult('Some Tutor'), ReportCategory::InappropriateContactWithMinor, null);

        $html = $this->actingAs($guardian)->get(route('guardian.home'))->assertOk()->getContent();

        $this->assertStringContainsString('1 urgent safety report about your child.', $html);
        $this->assertStringContainsString('Read the urgent report', $html);
        $this->assertSame(1, preg_match_all('/\sdata-primary-action[=\s>]/', $html));
        $this->assertLessThan(strpos($html, 'id="waiting-title"'), strpos($html, 'gd-urgent'));
    }

    public function test_the_operator_home_adds_no_query_of_its_own_and_every_figure_is_the_services(): void
    {
        $admin = $this->admin();
        $this->makeTutor(['status' => 'pending'], null, $this->makeUser('Applicant'));

        // What the two services cost on their own, then what the whole request costs.
        DB::flushQueryLog();
        DB::enableQueryLog();
        app(Queues::class)->for($admin);
        app(Overview::class)->compute(true);
        $services = count(DB::getQueryLog());

        DB::flushQueryLog();
        $page = $this->actingAs($admin)->get(route('ops.home'))->assertOk();
        $request = count(DB::getQueryLog());
        DB::disableQueryLog();

        // The rest is the session, the signed-in person, their roles, the entitlement check and the bell: the same on
        // every screen. Anything the new layout queried would grow this.
        $this->assertLessThanOrEqual($services + 25, $request, 'the operator home layout runs no extra query');
        $this->assertGreaterThan($services - 1, $request);

        $data = app(Overview::class)->compute(true);
        $html = $page->getContent();
        $this->assertStringContainsString('Tutor applications waiting: 1.', $html);
        $this->assertStringContainsString('Last '.$data['window_days'].' days', $html);
        $this->assertStringContainsString('data-count="'.$data['people']['tutors_active'].'"', $html);
    }

    public function test_the_weekly_chart_is_one_series_with_focusable_bars_and_a_table_view(): void
    {
        $subject = $this->makeSubject('Chart Maths');
        $tutor = $this->makeTutor([], $subject, $this->makeUser('Chart Tutor'));
        $this->makeSession($tutor, $this->adult('Chart Learner'), $subject, ['starts_at' => now()->addDay()]);

        $html = $this->actingAs($this->admin())->get(route('ops.home'))->assertOk()->getContent();

        $this->assertStringContainsString('data-chart', $html);
        $this->assertSame(12, preg_match_all('/class="hm-chart__hit"[^>]*tabindex="0"/', $html), 'twelve weeks, each bar focusable');
        $this->assertSame(12, preg_match_all('/class="hm-chart__hit"[^>]*aria-label="\d+ requests, week of/', $html));
        $this->assertStringContainsString('Show as a table', $html);
        $this->assertSame(1, preg_match_all('/hm-chart__bar--now/', $html), 'only the current week is the full hue');
        $this->assertStringNotContainsString('legend', strtolower((string) preg_replace('/<style.*?<\/style>/s', '', $html)), 'one series needs no legend');
    }

    public function test_an_operator_with_no_queues_sees_the_honest_empty_head(): void
    {
        // An academic editor has no work queue: curricula and subjects are not queues.
        $nobody = User::factory()->create();
        app(RoleAdministration::class)->setRoles(null, $nobody, [OpsRole::AcademicEditor->value]);

        $html = $this->actingAs($nobody->fresh())->get(route('ops.home'))->assertOk()->getContent();

        $this->assertStringContainsString('Your role has no work queues on this screen.', $html);
        $this->assertSame(1, preg_match_all('/\sdata-primary-action[=\s>]/', $html));
    }
}
