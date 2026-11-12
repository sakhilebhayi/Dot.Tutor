<?php

namespace Tests\Feature\PolishGuardian;

use App\Domain\Academic\Models\LearnerProfile;
use App\Domain\Money\Models\Order;
use App\Domain\Safety\Contracts\GuardianApprovals;
use App\Domain\Safety\Contracts\ReportSink;
use App\Domain\Safety\Enums\ReportCategory;
use App\Domain\Safety\Models\Incident;
use App\Domain\Safety\Models\Report;
use App\Domain\Safety\Services\Messenger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Tests\Feature\Safety\BuildsSafetyState;
use Tests\TestCase;

/** The guardian experience after the polish wave (critic A, guardian/34 to 37; D-2100 to D-2105). */
class GuardianExperienceTest extends TestCase
{
    use BuildsSafetyState;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
    }

    public function test_a_guardian_gets_their_own_navigation_on_desktop_and_phone(): void
    {
        $child = $this->child('Lerato');
        $guardian = $this->adult('Palesa');
        $this->linkGuardian($child, $guardian, false, 9);

        $page = $this->actingAs($guardian)->get(route('guardian.home'))->assertOk();
        $html = $page->getContent();
        $rail = Str::betweenFirst($html, 'class="td-rail__nav"', '</nav>');
        $bar = Str::betweenFirst($html, 'class="td-bottom"', '</nav>');

        foreach (['Children', 'Approvals', 'Safety', 'Messages', 'Payments', 'Notifications', 'Account'] as $label) {
            $this->assertStringContainsString($label, $rail, "rail has {$label}");
        }
        foreach (['Find a tutor', 'My rooms', 'Practice', 'Study assistant', 'Your learning'] as $label) {
            $this->assertStringNotContainsString($label, $rail, "rail has no {$label}");
        }
        foreach (['Children', 'Approvals', 'Safety', 'Messages'] as $label) {
            $this->assertStringContainsString($label, $bar, "phone bar has {$label}");
        }
        $this->assertMatchesRegularExpression('#href="'.preg_quote(route('guardian.home'), '#').'"\s+aria-current="page"#', $rail);

        // The safety page lights up its own item; every guardian link answers 200.
        $safety = $this->actingAs($guardian)->get(route('safety.guardian.dashboard'))->assertOk()->getContent();
        $this->assertMatchesRegularExpression('#href="'.preg_quote(route('safety.guardian.dashboard'), '#').'"\s+aria-current="page"#', $safety);
        foreach (['guardian.approvals', 'guardian.payments', 'safety.conversations.index', 'comms.inbox'] as $name) {
            $this->actingAs($guardian)->get(route($name))->assertOk();
        }
        // A guardian with no learning of their own keeps the guardian navigation on shared pages.
        $this->assertStringContainsString('Approvals', Str::betweenFirst($this->actingAs($guardian)->get(route('safety.conversations.index'))->getContent(), 'class="td-rail__nav"', '</nav>'));
    }

    public function test_a_guardian_who_also_learns_keeps_the_learner_navigation_on_learning_pages(): void
    {
        $child = $this->child('Lerato');
        $guardian = $this->adult('Palesa');
        $this->linkGuardian($child, $guardian, false, 9);
        LearnerProfile::query()->forceCreate(['user_id' => $guardian->id]);

        $guardianRail = Str::betweenFirst($this->actingAs($guardian)->get(route('guardian.home'))->getContent(), 'class="td-rail__nav"', '</nav>');
        $this->assertStringContainsString('Your learning', $guardianRail);
        $this->assertStringNotContainsString('Find a tutor', $guardianRail);

        $learnerRail = Str::betweenFirst($this->actingAs($guardian)->get(route('learn.home'))->getContent(), 'class="td-rail__nav"', '</nav>');
        $this->assertStringContainsString('Find a tutor', $learnerRail);
        $this->assertStringContainsString('Guardian', $learnerRail);

        // Someone who is nobody's guardian never gets the guardian navigation, even on /guardian.
        $minor = $this->child('Alone');
        $this->assertStringContainsString('Find a tutor', Str::betweenFirst($this->actingAs($minor)->get(route('guardian.home'))->getContent(), 'class="td-rail__nav"', '</nav>'));
    }

    public function test_the_safety_page_lists_each_report_about_the_guardians_own_children_only(): void
    {
        $child = $this->child('Lerato');
        $guardian = $this->adult('Palesa');
        $this->linkGuardian($child, $guardian, false, 9);
        $tutor = $this->adult('Some Tutor');

        $this->actingAs($guardian)->get(route('safety.guardian.dashboard'))->assertOk()
            ->assertSee('Safety')->assertSee('No reports about your children')->assertSee('10111')->assertSee('116');

        $report = app(ReportSink::class)->report($child, $tutor, ReportCategory::InappropriateContactWithMinor, 'He asked for my WhatsApp number.');
        $this->assertNotNull($report->incident_id, 'a child-safety report opens an incident');

        // Another family's report never shows.
        $other = $this->child('Not Yours');
        $this->linkGuardian($other, $this->adult('Other Parent'), false, 9);
        app(ReportSink::class)->report($other, $this->adult('Stranger'), ReportCategory::Harassment, 'Other family details');

        $this->actingAs($guardian)->get(route('safety.guardian.dashboard'))->assertOk()
            ->assertSee('Inappropriate contact with a learner under 18')
            ->assertSee('Lerato reported it')
            ->assertSee('Being looked at urgently')
            ->assertSee('What happens next')
            ->assertSee('id="report-'.$report->id.'"', false)
            ->assertDontSee('He asked for my WhatsApp number.')   // report text stays with the officers
            ->assertDontSee('Not Yours')->assertDontSee('Other family details')->assertDontSee('Harassment or bullying');

        // The incident moves on: acknowledged, then closed, and the page follows.
        Incident::query()->whereKey($report->incident_id)->update(['status' => Incident::ACKNOWLEDGED, 'acknowledged_at' => now()]);
        $this->actingAs($guardian)->get(route('safety.guardian.dashboard'))->assertSee('A safeguarding officer is handling it');

        Incident::query()->whereKey($report->incident_id)->update(['status' => Incident::CLOSED, 'closed_at' => now()]);
        Report::query()->whereKey($report->id)->update(['status' => Report::ACTIONED, 'resolved_at' => now()]);
        $this->actingAs($guardian)->get(route('safety.guardian.dashboard'))->assertSee('Closed');

        // The JSON answer of the same route is safety's own, unchanged.
        $this->actingAs($guardian)->getJson(route('safety.guardian.dashboard'))->assertOk()->assertJsonPath('children.0.name', 'Lerato');
    }

    public function test_a_report_made_by_someone_else_does_not_name_them(): void
    {
        $child = $this->child('Lerato');
        $guardian = $this->adult('Palesa');
        $this->linkGuardian($child, $guardian, false, 9);

        app(ReportSink::class)->report($this->adult('Named Reporter'), $child, ReportCategory::Spam, 'Posting adverts');

        $this->actingAs($guardian)->get(route('safety.guardian.dashboard'))->assertOk()
            ->assertSee('A report about Lerato')->assertSee('Spam or scam')
            ->assertDontSee('Named Reporter')->assertDontSee('Posting adverts');
    }

    public function test_an_urgent_report_leads_the_home_and_child_pages_and_the_badge_tells_the_truth(): void
    {
        $child = $this->child('Lerato');
        $guardian = $this->adult('Palesa');
        $this->linkGuardian($child, $guardian, false, 9);

        $this->actingAs($guardian)->get(route('guardian.home'))->assertOk()->assertSee('Active')->assertDontSee('urgent report');

        $report = app(ReportSink::class)->report($child, $this->adult('Some Tutor'), ReportCategory::InappropriateContactWithMinor, null);

        $home = $this->actingAs($guardian)->get(route('guardian.home'))->assertOk()->getContent();
        $this->assertStringContainsString('1 urgent report', $home);
        $this->assertStringNotContainsString('>Active<', $home);
        $this->assertStringContainsString('class="gd-urgent"', $home);
        $this->assertStringContainsString(route('safety.guardian.dashboard').'#report-'.$report->id, $home);
        // The band comes before "Waiting for you".
        $this->assertLessThan(strpos($home, 'Waiting for you'), strpos($home, 'class="gd-urgent"'));

        $page = $this->actingAs($guardian)->get(route('guardian.child', $child->id))->assertOk()->getContent();
        $this->assertLessThan(strpos($page, 'Waiting for you'), strpos($page, 'class="gd-urgent"'));
        $this->assertStringContainsString('Under 13 · you are their parent', $page);
    }

    public function test_a_declined_request_leaves_a_trace_in_recent_decisions(): void
    {
        $child = $this->teen('Kabelo');
        $guardian = $this->adult('Mpho');
        $this->linkGuardian($child, $guardian, false, 15);
        $approval = app(GuardianApprovals::class)->request($child, 'purchase', $child, ['summary' => 'Grade 10 Physics pack', 'amount_minor' => 15000, 'currency' => 'ZAR']);

        $this->actingAs($guardian)->post(route('safety.guardian.approvals.decide', $approval->id), ['decision' => 'decline', 'note' => 'After exams'])->assertRedirect();

        $this->actingAs($guardian)->get(route('guardian.home'))->assertOk()
            ->assertSee('Nothing is waiting for your approval')
            ->assertSee('Recent decisions')->assertSee('Grade 10 Physics pack')->assertSee('You declined')
            ->assertSee('After exams')->assertSee('R150.00');

        $this->actingAs($guardian)->get(route('guardian.approvals'))->assertOk()
            ->assertSee('Recent decisions')->assertSee('Grade 10 Physics pack')->assertSee('You declined');

        // A second guardian sees the same decision as another guardian's.
        $second = $this->adult('Second Parent');
        $this->linkGuardian($child, $second, false, 15);
        $this->actingAs($second)->get(route('guardian.approvals'))->assertSee('Another guardian declined');

        // A stranger sees none of it.
        $this->actingAs($this->adult('Stranger'))->get(route('guardian.approvals'))->assertOk()->assertDontSee('Grade 10 Physics pack');
    }

    public function test_payments_lists_the_childrens_orders_in_one_money_format(): void
    {
        $child = $this->child('Lerato');
        $guardian = $this->adult('Palesa');
        $this->linkGuardian($child, $guardian, false, 9);

        $this->actingAs($guardian)->get(route('guardian.payments'))->assertOk()->assertSee('No purchases');

        $order = Order::query()->forceCreate(['uuid' => (string) Str::uuid(), 'buyer_id' => $child->id, 'status' => 'paid', 'currency' => 'ZAR', 'subtotal_minor' => 25000, 'total_minor' => 25000, 'paid_by' => $guardian->id]);
        $order->lines()->forceCreate(['purchasable_type' => 'course', 'purchasable_id' => 1, 'title' => 'Grade 4 Fractions', 'commission_category' => 'course', 'earning_release' => 'after_window', 'price_minor' => 25000, 'cash_minor' => 25000, 'commission_bps' => 1500, 'commission_minor' => 3750, 'seller_net_minor' => 21250]);
        $stranger = $this->child('Someone Else');
        Order::query()->forceCreate(['uuid' => (string) Str::uuid(), 'buyer_id' => $stranger->id, 'status' => 'paid', 'currency' => 'ZAR', 'subtotal_minor' => 9900, 'total_minor' => 9900]);

        $this->actingAs($guardian)->get(route('guardian.payments'))->assertOk()
            ->assertSee('Grade 4 Fractions')->assertSee('R250.00')->assertSee('paid by a guardian')
            ->assertDontSee('R99.00');
    }

    public function test_a_guardian_reads_messages_in_the_guardian_frame_and_others_keep_safetys_page(): void
    {
        $child = $this->child('Lerato');
        $guardian = $this->adult('Palesa');
        $this->linkGuardian($child, $guardian, false, 9);
        $message = app(Messenger::class)->start($child, 'support', null, [], 'I need help with my lesson', 'Help please');

        $this->actingAs($guardian)->get(route('safety.conversations.index'))->assertOk()
            ->assertSee('Help please')->assertSee('Ask Dot.Tutor support')->assertSee('id="support"', false)->assertSee('td-app--learner', false);
        $this->actingAs($guardian)->get(route('safety.conversations.show', $message->conversation_id))->assertOk()
            ->assertSee('I need help with my lesson')->assertSee('All messages');

        // The child (not a guardian) keeps the safety domain's own page.
        $this->actingAs($child)->get(route('safety.conversations.index'))->assertOk()->assertSee('Help please')->assertDontSee('id="support"', false);
    }
}
