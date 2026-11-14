<?php

namespace Tests\Feature\Ops;

use App\Domain\Integration\Support\TutorStanding;
use App\Domain\Marketplace\Enums\QualificationStatus;
use App\Domain\Marketplace\Models\TutorQualification;
use App\Domain\Ops\Access\OpsRole;
use App\Domain\Ops\Access\RoleAdministration;
use App\Domain\Ops\Support\PlainWords;
use App\Domain\Safety\Contracts\ReportSink;
use App\Domain\Safety\Enums\ReportCategory;
use App\Domain\Safety\Models\Incident;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\BuildsMoney;
use Tests\Feature\Community\BuildsCommunity;
use Tests\TestCase;

/**
 * R-47 fix round (fix-ops, D-2400 to D-2408): the defects critic B found on the operations and tutor screens.
 */
class FixOpsTest extends TestCase
{
    use BuildsCommunity, BuildsMoney {
        BuildsCommunity::makeUser insteadof BuildsMoney;
        BuildsCommunity::makeSubject insteadof BuildsMoney;
        BuildsCommunity::makeCompany insteadof BuildsMoney;
        BuildsCommunity::makeTutorIn insteadof BuildsMoney;
        BuildsCommunity::stampSession insteadof BuildsMoney;
        BuildsCommunity::makeOperator insteadof BuildsMoney;
        BuildsCommunity::makeTutor insteadof BuildsMoney;
        BuildsCommunity::openWeek insteadof BuildsMoney;
        BuildsCommunity::slotAt insteadof BuildsMoney;
        BuildsCommunity::makeSession insteadof BuildsMoney;
        BuildsCommunity::bookingPayload insteadof BuildsMoney;
    }
    use RefreshDatabase;

    private function withRole(OpsRole $role, string $name = 'Operator'): User
    {
        $user = User::factory()->create(['name' => $name]);
        app(RoleAdministration::class)->setRoles(null, $user, [$role->value]);

        return $user->fresh();
    }

    public function test_money_times_on_the_ops_screens_are_in_the_readers_zone(): void
    {
        $this->seedAcademic();
        $this->setUpPaystack();
        config(['tutor.timezone' => 'Africa/Johannesburg']);
        $this->travelTo(CarbonImmutable::parse('2026-11-12 21:33:00', 'UTC'));
        $order = $this->paidLessonOrder();
        $finance = $this->withRole(OpsRole::Finance, 'Finance');

        // Stored 21:33 UTC; an operator in South Africa reads 23:33, the same as the order page.
        $this->actingAs($finance)->get(route('ops.payments'))->assertOk()->assertSee('12 Nov 23:33')->assertDontSee('12 Nov 21:33')
            ->assertSee('Times are South African time (SAST).');
        $this->actingAs($finance)->get(route('ops.payments.order', $order))->assertOk()->assertSee('12 Nov 2026, 23:33');
    }

    public function test_a_verified_qualification_without_a_level_reads_the_same_everywhere_and_prompts_the_reviewer(): void
    {
        $this->seedAcademic();
        [, , $tutor, $profile] = $this->lesson();
        TutorQualification::query()->forceCreate(['tutor_profile_id' => $profile->id, 'kind' => 'degree', 'title' => 'BSc Mathematics', 'status' => QualificationStatus::Verified->value, 'reviewed_at' => now()]);

        $facts = collect(app(TutorStanding::class)->facts($profile->fresh()))->keyBy('key');
        $this->assertSame('Qualification checked, level pending', $facts['qualification']['text']);
        $this->assertNull($facts['qualification']['ok']);

        $reviewer = $this->withRole(OpsRole::VerificationReviewer, 'Reviewer');
        $this->actingAs($reviewer)->get(route('ops.verification.tutor', $profile))->assertOk()
            ->assertSee('BSc Mathematics is verified, but no level is set')
            ->assertSee('Save level: Verified qualified tutor')
            ->assertSee('Qualification checked, level pending');

        // The tutor sees the same state, once, and what is left instead of a third "Approved".
        $this->actingAs($tutor)->get(route('teach.profile', ['step' => 'send']))->assertOk()
            ->assertSee('Qualification checked, level pending')
            ->assertSee('What is left')->assertSee('A reviewer sets your verification level next')
            ->assertDontSee('Qualification not verified')->assertDontSee('Go to your workspace');

        // Saving the suggested level settles it everywhere.
        $this->actingAs($reviewer)->post(route('ops.verification.level', $profile), ['level' => 'verified_qualified_tutor', 'reason' => 'Supported by the verified record: BSc Mathematics'])->assertRedirect();
        $this->assertSame('Qualification verified', collect(app(TutorStanding::class)->facts($profile->fresh()))->keyBy('key')['qualification']['text']);
        $this->assertNull(app(TutorStanding::class)->levelWaiting($profile->fresh()));
    }

    public function test_dense_tutor_pages_keep_the_one_workspace_shell_size(): void
    {
        $this->seedAcademic();
        [, , $tutor] = $this->lesson();

        $html = $this->actingAs($tutor)->get(route('teach.bookings'))->assertOk()->getContent();
        $this->assertDoesNotMatchRegularExpression('/<html[^>]*data-td-step/', $html);
        $this->assertStringContainsString('class="td-main content-wrap" data-shell-behind  data-td-step="dense"', $html);

        $standard = $this->actingAs($tutor)->get(route('teach.home'))->assertOk()->getContent();
        $this->assertDoesNotMatchRegularExpression('/data-td-step/', $standard);
    }

    public function test_the_incident_page_names_the_reported_person_and_protects_first(): void
    {
        Notification::fake();
        $this->freezeTime();
        $officer = $this->withRole(OpsRole::SafeguardingOfficer, 'Officer');
        $child = $this->child('Child');
        $adult = $this->adult('Jacob Venter');
        $report = app(ReportSink::class)->report($child, $adult, ReportCategory::InappropriateContactWithMinor, 'Unsafe.');
        $incident = Incident::query()->firstOrFail();

        $this->actingAs($officer)->get(route('ops.safeguarding.incidents.show', $incident))->assertOk()
            ->assertSee('Protect first')->assertSee('Reported person:')->assertSee('Jacob Venter')
            ->assertSee('Suspend Jacob Venter')
            ->assertSee('Acknowledge within 60 min')->assertSee('data-countdown', false)
            ->assertSee(route('ops.safeguarding.reports.act', $report), false);

        // The button posts to the report's own action (safety's Moderation service); afterwards the page says so.
        $this->actingAs($officer)->from(route('ops.safeguarding.incidents.show', $incident))
            ->post(route('ops.safeguarding.reports.act', $report), ['action' => 'suspend', 'reason' => 'Contact with a child outside a lesson'])
            ->assertRedirect(route('ops.safeguarding.incidents.show', $incident));
        $this->actingAs($officer)->get(route('ops.safeguarding.incidents.show', $incident))->assertOk()
            ->assertSee('Suspended')->assertDontSee('Suspend Jacob Venter');

        $this->travel(75)->minutes();
        $this->actingAs($officer)->get(route('ops.safeguarding.incidents.show', $incident))->assertSee('Overdue by 15 min');
    }

    public function test_audit_history_names_people_and_reads_codes_as_words(): void
    {
        $this->seedAcademic();
        [, , $tutor, $profile] = $this->lesson();
        $labels = PlainWords::subjects([
            (object) ['subject_type' => User::class, 'subject_id' => $tutor->id],
            (object) ['subject_type' => 'tutor_profile', 'subject_id' => $profile->id],
            (object) ['subject_type' => 'App\\Domain\\Safety\\Models\\Report', 'subject_id' => 9],
        ]);

        $this->assertSame($tutor->name, PlainWords::subject(User::class, $tutor->id, $labels));
        $this->assertSame($tutor->name."'s tutor profile", PlainWords::subject('tutor_profile', $profile->id, $labels));
        $this->assertSame('Report 9', PlainWords::subject('App\\Domain\\Safety\\Models\\Report', 9, $labels));
        $this->assertSame('Reason: Ended by the tutor', PlainWords::details(['reason' => 'tutor_ended', 'again' => false, 'note' => null]));
        $this->assertSame('Qualification: 3, 4; Kind: Degree', PlainWords::details(['qualification_ids' => [3, 4], 'kind' => 'Degree']));
    }

    public function test_today_puts_the_next_lesson_first(): void
    {
        $this->seedAcademic();
        [$session, , $tutor] = $this->lesson();
        $session->forceFill(['status' => 'confirmed', 'starts_at' => now()->addMinutes(30)])->save();

        $html = $this->actingAs($tutor)->get(route('teach.home'))->assertOk()->getContent();
        // R-51: the head says the next lesson in a sentence (the first thing on the page), and with no request the
        // "Needs your answer" list is one line, so it never pushes the lessons down.
        $this->assertStringContainsString('Your next lesson is', $html);
        $this->assertLessThan(strpos($html, 'Needs your answer'), strpos($html, 'Your next lesson is'));
        $this->assertStringContainsString('Lessons, today and next 7 days', $html);
        $this->assertStringContainsString('No requests waiting', $html);
    }
}
