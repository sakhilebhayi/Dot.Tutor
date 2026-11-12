<?php

namespace Tests\Feature\Ops;

use App\Domain\Community\Models\Post;
use App\Domain\Marketplace\Enums\QualificationStatus;
use App\Domain\Marketplace\Models\TutorQualification;
use App\Domain\Money\Models\FinanceIssue;
use App\Domain\Money\Services\FinanceIssues;
use App\Domain\Ops\Access\OpsRole;
use App\Domain\Ops\Access\RoleAdministration;
use App\Domain\Safety\Contracts\ReportSink;
use App\Domain\Safety\Enums\ReportCategory;
use App\Domain\Safety\Models\AccountRestriction;
use App\Domain\Safety\Models\AuditEntry;
use App\Domain\Safety\Models\Incident;
use App\Domain\Safety\Models\Report;
use App\Domain\Safety\Services\Messenger;
use App\Enums\SessionStatus;
use App\Models\SessionRating;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\BuildsMoney;
use Tests\Feature\Community\BuildsCommunity;
use Tests\TestCase;

/**
 * Every command-centre screen renders with real rows (and, in OperatorRouteMatrixTest, with none), shows the real
 * figures, and its actions go through the owning domain's service and the safety audit log.
 */
class CommandCentreScreensTest extends TestCase
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

    private function admin(): User
    {
        $admin = User::factory()->create(['name' => 'Ops Admin']);
        app(RoleAdministration::class)->setRoles(null, $admin, [OpsRole::Admin->value]);

        return $admin->fresh();
    }

    public function test_every_screen_renders_with_real_data_and_shows_real_figures(): void
    {
        Notification::fake();
        $this->seedAcademic();
        $this->setUpPaystack();
        $admin = $this->admin();

        // A paid lesson (money, ledger, order), a completed and reviewed lesson, a disputed lesson.
        $order = $this->paidLessonOrder();
        [$done, $learner, , $profile] = $this->lesson();
        $done->forceFill(['status' => SessionStatus::Completed->value, 'starts_at' => now()->subDays(2)])->save();
        SessionRating::query()->forceCreate(['session_id' => $done->id, 'rated_by' => $learner->id, 'rating' => 5, 'review' => 'Clear explanations of factorising.', 'status' => 'published']);
        [$disputed] = $this->lesson();
        $disputed->forceFill(['status' => SessionStatus::Disputed->value, 'starts_at' => now()->subDay()])->save();

        // Verification: a qualification sent for review.
        TutorQualification::query()->forceCreate(['tutor_profile_id' => $profile->id, 'kind' => 'degree', 'title' => 'BSc Mathematics', 'institution' => 'University of Pretoria', 'status' => QualificationStatus::Submitted->value, 'submitted_at' => now()]);

        // Safeguarding: a child with a guardian, a child-safety report that opens a critical incident.
        $child = $this->child('Lerato Child');
        $this->linkGuardian($child, $this->adult('Nomsa Guardian'), false, 9);
        app(ReportSink::class)->report($child, $profile->user, ReportCategory::InappropriateContactWithMinor, 'Asked for my number.');

        // Content: a held room post by a learner under 13.
        $room = $this->subjectRoom();
        Post::query()->forceCreate(['room_id' => $room->id, 'author_id' => $child->id, 'kind' => 'question', 'title' => 'How do I factorise?', 'body' => 'x squared minus four', 'status' => Post::HELD, 'held_reason' => 'pre_moderation']);

        // Money: an unmatched payment exception. Support: a thread.
        app(FinanceIssues::class)->raise('unmatched_payment', 'unmatched:ref-1', 'Paystack charge ref-1 matches no order', ['amount_minor' => 15000, 'currency' => 'ZAR', 'severity' => 'critical']);
        app(Messenger::class)->start($learner, 'support', null, [], 'My payment went through twice', 'Double payment');

        $this->actingAs($admin);

        $this->get(route('ops.home'))->assertOk()
            ->assertSee('Child-safety reports')->assertSee('Qualification evidence to review')->assertSee('Held room posts')
            ->assertSee('Lessons held')->assertSee('Unmatched payments')->assertSee('Platform commission')
            ->assertSee('5 of 5');

        $this->get(route('ops.users'))->assertOk()->assertSee('Lerato Child')->assertSee('Under 13');
        $this->get(route('ops.users', ['flag' => 'minor']))->assertOk()->assertSee('Lerato Child')->assertDontSee('Nomsa Guardian');
        $this->get(route('ops.users.show', $child))->assertOk()->assertSee('Nomsa Guardian');
        $this->get(route('ops.users.team'))->assertOk()->assertSee('Ops Admin');

        $this->get(route('ops.verification'))->assertOk()->assertSee('BSc Mathematics');
        $this->get(route('ops.verification', ['tab' => 'tutors']))->assertOk();
        $this->get(route('ops.verification', ['tab' => 'languages']))->assertOk();
        $this->get(route('ops.verification', ['tab' => 'applications']))->assertOk();
        $this->get(route('ops.verification.tutor', $profile))->assertOk()->assertSee('Not screened');

        $report = Report::query()->firstOrFail();
        $this->get(route('ops.safeguarding'))->assertOk()->assertSee('A child-safety report is open')->assertSee('Child safety · 1 hour');
        $this->get(route('ops.safeguarding.reports.show', $report))->assertOk()->assertSee('Asked for my number.');
        $incident = Incident::query()->firstOrFail();
        $this->get(route('ops.safeguarding.incidents'))->assertOk()->assertSee($incident->reference);
        $this->get(route('ops.safeguarding.incidents.show', $incident))->assertOk()->assertSee('Nobody owns this incident yet');
        $this->get(route('ops.safeguarding.minors'))->assertOk()->assertSee('Lerato Child');
        $this->get(route('ops.safeguarding.screening'))->assertOk()->assertSee('Approved tutors without a current screening');
        $this->get(route('ops.safeguarding.integrity'))->assertOk();
        $this->get(route('ops.audit'))->assertOk()->assertSee('safety.report.filed');
        $this->get(route('ops.audit', ['verify' => 1]))->assertOk()->assertSee('Chain intact');

        $this->get(route('ops.moderation'))->assertOk()->assertSee('How do I factorise?')->assertSee('Learner under 13');
        foreach (['resources', 'complaints', 'library', 'courses'] as $tab) {
            $this->get(route('ops.moderation', ['tab' => $tab]))->assertOk();
        }

        $this->get(route('ops.reviews'))->assertOk()->assertSee('Disputed lessons')->assertSee('Lesson stands');
        $this->get(route('ops.reviews.bookings'))->assertOk();
        $this->get(route('ops.reviews.booking', $disputed))->assertOk()->assertSee('Resolve the dispute');

        $this->get(route('ops.payments'))->assertOk()->assertSee('Paid');
        $this->get(route('ops.payments', ['tab' => 'exceptions']))->assertOk()->assertSee('Unmatched payment');
        foreach (['refunds', 'disputes', 'commission'] as $tab) {
            $this->get(route('ops.payments', ['tab' => $tab]))->assertOk();
        }
        $this->get(route('ops.payments.order', $order))->assertOk()->assertSee('Refund through Paystack');
        $this->get(route('ops.payouts'))->assertOk()->assertSee('Available to pay');

        $this->get(route('ops.support'))->assertOk()->assertSee('Double payment')->assertSee('Waiting for us');
        $thread = DB::table('safety_conversations')->where('context_type', 'support')->value('id');
        $this->get(route('ops.support.show', $thread))->assertOk()->assertSee('My payment went through twice');

        $this->get(route('ops.academic'))->assertOk()->assertSee('Subject offerings');
        $this->get(route('ops.subjects'))->assertOk()->assertSee('Subjects');
        $this->get(route('ops.languages'))->assertOk()->assertSee('isiZulu');
    }

    public function test_safeguarding_actions_go_through_safety_and_are_audited(): void
    {
        Notification::fake();
        $officer = User::factory()->create();
        app(RoleAdministration::class)->setRoles(null, $officer, [OpsRole::SafeguardingOfficer->value]);
        $child = $this->child('Child');
        $adult = $this->adult('Stranger');
        $report = app(ReportSink::class)->report($child, $adult, ReportCategory::InappropriateContactWithMinor, 'Unsafe.');
        $incident = Incident::query()->firstOrFail();

        $this->actingAs($officer)->post(route('ops.safeguarding.reports.act', $report), ['action' => 'suspend', 'reason' => 'Contact with a child outside a lesson', 'days' => 30])
            ->assertRedirect()->assertSessionHas('success');
        $this->assertTrue(AccountRestriction::query()->where('user_id', $adult->id)->where('kind', 'suspension')->exists());
        $this->assertSame(Report::ACTIONED, $report->fresh()->status);

        $this->actingAs($officer)->post(route('ops.safeguarding.incidents.update', $incident), ['action' => 'acknowledge'])->assertRedirect();
        $this->assertSame(Incident::ACKNOWLEDGED, $incident->fresh()->status);
        $this->assertSame($officer->id, $incident->fresh()->owner_id);

        $this->actingAs($officer)->post(route('ops.users.restrict', $adult), ['action' => 'unsuspend', 'reason' => 'Cleared after review'])->assertRedirect();
        $this->assertFalse(AccountRestriction::query()->inEffect()->where('user_id', $adult->id)->where('kind', 'suspension')->exists());

        $this->actingAs($officer)->get(route('ops.safeguarding.reports.show', $report))->assertOk();
        $this->assertTrue(AuditEntry::query()->where('action', 'safety.report.viewed')->where('actor_id', $officer->id)->exists());
        $this->assertTrue(AuditEntry::query()->where('action', 'like', 'safety.moderation%')->orWhere('action', 'like', 'safety.account%')->exists()
            || AuditEntry::query()->where('actor_id', $officer->id)->count() >= 3);
    }

    public function test_content_and_dispute_actions_use_the_owning_services(): void
    {
        Notification::fake();
        $this->seedAcademic();
        $moderator = User::factory()->create();
        app(RoleAdministration::class)->setRoles(null, $moderator, [OpsRole::ContentModerator->value]);
        $support = User::factory()->create();
        app(RoleAdministration::class)->setRoles(null, $support, [OpsRole::SupportAgent->value]);

        $child = $this->child('Kid');
        $post = Post::query()->forceCreate(['room_id' => $this->subjectRoom()->id, 'author_id' => $child->id, 'kind' => 'question', 'body' => 'Help with fractions', 'status' => Post::HELD, 'held_reason' => 'pre_moderation']);
        $this->actingAs($moderator)->post(route('ops.moderation.posts.decide', $post), ['decision' => 'release'])->assertRedirect()->assertSessionHas('success');
        $this->assertNotSame(Post::HELD, $post->fresh()->status);
        $this->assertTrue(AuditEntry::query()->where('action', 'community.post.released')->exists());

        [$lesson] = $this->lesson();
        $lesson->forceFill(['status' => SessionStatus::Disputed->value])->save();
        $this->actingAs($support)->post(route('ops.reviews.resolve', $lesson), ['outcome' => 'completed', 'refund_percent' => 0, 'reason' => 'Lesson took place, both agree'])->assertRedirect()->assertSessionHas('success');
        $this->assertSame(SessionStatus::Completed->value, $lesson->fresh()->status);
    }

    public function test_finance_resolves_an_exception_and_others_cannot_see_money(): void
    {
        $finance = User::factory()->create();
        app(RoleAdministration::class)->setRoles(null, $finance, [OpsRole::Finance->value]);
        $issue = app(FinanceIssues::class)->raise('unmatched_payment', 'unmatched:ref-9', 'Charge ref-9 matches no order', ['amount_minor' => 5000, 'currency' => 'ZAR']);

        $this->actingAs($finance)->post(route('ops.payments.issues.resolve', $issue), ['note' => 'Refunded in the Paystack dashboard'])->assertRedirect();
        $this->assertNotSame('open', FinanceIssue::query()->find($issue->id)->status);

        $moderator = User::factory()->create();
        app(RoleAdministration::class)->setRoles(null, $moderator, [OpsRole::ContentModerator->value]);
        $this->actingAs($moderator)->get(route('ops.home'))->assertOk()->assertDontSee('Platform commission')->assertDontSee('Finance exceptions');
        $this->actingAs($finance)->get(route('ops.home'))->assertOk()->assertSee('Platform commission')->assertDontSee('Child-safety reports');
    }

    public function test_the_overview_says_nothing_is_recorded_when_nothing_is(): void
    {
        $this->actingAs($this->admin())->get(route('ops.home'))->assertOk()
            ->assertSee('Nothing is waiting in your queues')
            ->assertSee('No lessons requested in the last 12 weeks')
            ->assertSee('No learner has had a lesson in the last six months')
            ->assertSee('No published reviews yet');
    }
}
