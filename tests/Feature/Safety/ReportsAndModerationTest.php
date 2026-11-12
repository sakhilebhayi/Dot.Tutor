<?php

namespace Tests\Feature\Safety;

use App\Domain\Safety\Contracts\ReportSink;
use App\Domain\Safety\Contracts\SafeguardingGate;
use App\Domain\Safety\Enums\ReportCategory;
use App\Domain\Safety\Models\AccountRestriction;
use App\Domain\Safety\Models\Incident;
use App\Domain\Safety\Models\IntegrityCase;
use App\Domain\Safety\Models\Message;
use App\Domain\Safety\Models\ModerationAction;
use App\Domain\Safety\Models\Report;
use App\Domain\Safety\Notifications\AccountWarned;
use App\Domain\Safety\Notifications\IncidentAlert;
use App\Models\TutorSession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use LogicException;
use Tests\TestCase;

class ReportsAndModerationTest extends TestCase
{
    use BuildsSafetyState;
    use RefreshDatabase;

    /** @return array{0: User, 1: User, 2: Message} adult sender, adult recipient, message */
    private function adultMessage(string $body = 'Buy followers cheap'): array
    {
        $a = $this->adult('Sender');
        $b = $this->adult('Recipient');
        $this->actingAs($a)->postJson(route('safety.conversations.store'), ['context_type' => 'direct', 'recipient_ids' => [$b->id], 'body' => $body])->assertCreated();

        return [$a, $b, Message::sole()];
    }

    public function test_a_participant_reports_a_message_and_it_joins_the_queue(): void
    {
        [$sender, $recipient, $message] = $this->adultMessage();

        $this->actingAs($recipient)->postJson(route('safety.messages.report', $message), ['category' => 'spam', 'details' => 'Selling things'])
            ->assertCreated()->assertJsonPath('report.priority', 'low');

        $report = Report::sole();
        $this->assertSame($sender->id, $report->reported_user_id);
        $this->assertSame('Buy followers cheap', $report->snapshot['body']);

        $this->actingAs($this->operator())->getJson(route('safety.operator.queue'))->assertOk()
            ->assertJsonPath('reports.0.id', $report->id)->assertJsonPath('counts.low', 1);
    }

    public function test_reporting_twice_returns_the_same_open_report(): void
    {
        [, $recipient, $message] = $this->adultMessage();

        $first = $this->actingAs($recipient)->postJson(route('safety.messages.report', $message), ['category' => 'spam'])->json('report.id');
        $second = $this->actingAs($recipient)->postJson(route('safety.messages.report', $message), ['category' => 'spam'])->json('report.id');

        $this->assertSame($first, $second);
    }

    public function test_you_cannot_report_a_message_you_cannot_see(): void
    {
        [, , $message] = $this->adultMessage();

        $this->actingAs($this->adult('Outsider'))->postJson(route('safety.messages.report', $message), ['category' => 'spam'])->assertForbidden();
        $this->actingAs($this->adult('Outsider2'))->postJson(route('safety.reports.store'), ['type' => 'message', 'id' => $message->id, 'category' => 'spam'])->assertForbidden();
        $this->assertSame(0, Report::count());
    }

    public function test_the_generic_endpoint_accepts_only_known_types(): void
    {
        $profile = $this->makeTutor([], null, $this->adult('Tutor'));
        $reporter = $this->adult('Reporter');

        $this->actingAs($reporter)->postJson(route('safety.reports.store'), ['type' => 'tutor_profile', 'id' => $profile->id, 'category' => 'misinformation'])
            ->assertCreated();
        $this->actingAs($reporter)->postJson(route('safety.reports.store'), ['type' => 'users_table', 'id' => 1, 'category' => 'spam'])
            ->assertUnprocessable()->assertJsonPath('error', 'validation_failed');
        $this->assertSame($profile->user_id, Report::sole()->reported_user_id);
    }

    public function test_a_child_safety_report_takes_the_fast_path(): void
    {
        $child = $this->child();
        $guardian = $this->adult('Mother');
        $this->linkGuardian($child, $guardian);
        $tutor = $this->adult('Tutor');
        $this->screen($tutor);
        $session = TutorSession::withoutEvents(fn () => $this->makeSession($this->makeTutor([], null, $tutor), $child, $this->makeSubject('Maths')));
        $this->actingAs($tutor)->postJson(route('safety.conversations.store'), ['context_type' => 'booking', 'context_id' => $session->id, 'body' => 'Keep this secret from your mum'])->assertCreated();
        $operator = $this->operator();
        Notification::fake();

        $this->actingAs($guardian)->postJson(route('safety.messages.report', Message::sole()), ['category' => 'inappropriate_contact_minor'])
            ->assertCreated()->assertJsonPath('report.priority', 'critical')->assertJsonPath('report.status', 'escalated');

        $incident = Incident::sole();
        $this->assertSame('critical', $incident->severity);
        $this->assertSame($child->id, $incident->learner_id);
        $this->assertTrue(Report::sole()->involves_minor);
        $this->assertTrue($incident->respond_by->lessThanOrEqualTo(now()->addHour()));
        Notification::assertSentTo($operator, IncidentAlert::class);
    }

    public function test_harassment_involving_a_minor_is_high_priority(): void
    {
        $teen = $this->teen();
        $this->linkGuardian($teen);
        $report = app(ReportSink::class)->report($teen, $this->adult('Bully'), ReportCategory::Harassment);

        $this->assertSame('high', $report->priority->value);
        $this->assertSame(0, Incident::count());
    }

    public function test_a_cheating_request_opens_an_integrity_case(): void
    {
        [, $recipient, $message] = $this->adultMessage('Write my exam for me tomorrow, I pay R500');

        $this->actingAs($recipient)->postJson(route('safety.messages.report', $message), ['category' => 'cheating_request'])->assertCreated();

        $case = IntegrityCase::sole();
        $this->assertSame('cheating_request', $case->kind->value);
        $this->assertSame(Report::sole()->id, $case->report_id);
    }

    public function test_moderators_hide_and_restore_and_participants_see_the_removal(): void
    {
        [$sender, $recipient, $message] = $this->adultMessage();
        $this->actingAs($recipient)->postJson(route('safety.messages.report', $message), ['category' => 'spam']);
        $report = Report::sole();
        $operator = $this->operator();

        $this->actingAs($operator)->postJson(route('safety.operator.reports.act', $report), ['action' => 'hide', 'reason' => 'Spam'])
            ->assertCreated()->assertJsonPath('report.status', 'actioned');

        $this->assertTrue($message->fresh()->isHidden());
        $this->actingAs($recipient)->getJson(route('safety.conversations.show', $message->conversation_id))
            ->assertJsonPath('messages.0.body', null)->assertJsonPath('messages.0.hidden', true);

        $this->actingAs($operator)->postJson(route('safety.operator.reports.act', $report), ['action' => 'restore', 'reason' => 'Mistake'])->assertCreated();
        $this->assertFalse($message->fresh()->isHidden());
        $this->assertSame(2, ModerationAction::count());
        $this->assertDatabaseHas('safety_audit_log', ['action' => 'safety.moderation.hide', 'actor_id' => $operator->id]);
    }

    public function test_content_that_cannot_be_hidden_here_says_so(): void
    {
        $reporter = $this->adult('Reporter');
        $profile = $this->makeTutor([], null, $this->adult('Tutor'));
        $report = app(ReportSink::class)->report($reporter, $profile, ReportCategory::Misinformation);

        $this->actingAs($this->operator())->postJson(route('safety.operator.reports.act', $report), ['action' => 'hide', 'reason' => 'x'])
            ->assertUnprocessable();
    }

    public function test_a_suspension_blocks_the_person_server_side_until_lifted(): void
    {
        [$sender, $recipient, $message] = $this->adultMessage();
        $this->actingAs($recipient)->postJson(route('safety.messages.report', $message), ['category' => 'harassment']);
        $operator = $this->operator();
        Notification::fake();

        $this->actingAs($operator)->postJson(route('safety.operator.reports.act', Report::sole()), ['action' => 'suspend', 'reason' => 'Harassment', 'days' => 7])
            ->assertCreated();

        Notification::assertSentTo($sender, AccountWarned::class);
        $this->actingAs($sender)->postJson(route('safety.conversations.messages.store', $message->conversation_id), ['body' => 'again'])
            ->assertForbidden()->assertJsonPath('reasons.0.code', 'account_suspended');
        $this->assertSame(7, (int) round(now()->diffInDays(AccountRestriction::sole()->ends_at)));

        $this->actingAs($operator)->postJson(route('safety.operator.users.act', $sender), ['action' => 'unsuspend', 'reason' => 'Appeal upheld'])->assertCreated();
        $this->assertTrue(app(SafeguardingGate::class)->allows($sender->fresh(), 'message', $message->conversation)->allowed);
    }

    public function test_a_suspension_ends_on_its_own(): void
    {
        $person = $this->adult('Person');
        $this->actingAs($this->operator())->postJson(route('safety.operator.users.act', $person), ['action' => 'suspend', 'reason' => 'Cool off', 'days' => 1])->assertCreated();

        $this->assertTrue(app(SafeguardingGate::class)->allows($person, 'publish')->hasReason('account_suspended'));
        $this->travel(25)->hours();
        $this->assertTrue(app(SafeguardingGate::class)->allows($person, 'publish')->allowed);
    }

    public function test_an_operator_cannot_suspend_themselves(): void
    {
        $operator = $this->operator();

        $this->actingAs($operator)->postJson(route('safety.operator.users.act', $operator), ['action' => 'suspend', 'reason' => 'x'])->assertUnprocessable();
    }

    public function test_warn_escalate_and_dismiss(): void
    {
        [$sender, $recipient, $message] = $this->adultMessage();
        $this->actingAs($recipient)->postJson(route('safety.messages.report', $message), ['category' => 'other']);
        $report = Report::sole();
        $operator = $this->operator();

        $this->actingAs($operator)->postJson(route('safety.operator.reports.act', $report), ['action' => 'escalate', 'reason' => 'Needs a look', 'severity' => 'medium'])->assertCreated();
        $this->assertSame('medium', Incident::sole()->severity);
        $this->assertSame(Report::ESCALATED, $report->fresh()->status);

        $this->actingAs($operator)->postJson(route('safety.operator.reports.act', $report), ['action' => 'warn', 'reason' => 'Mind your words'])->assertCreated();
        $this->assertSame(AccountRestriction::WARNING, AccountRestriction::sole()->kind);

        $other = app(ReportSink::class)->report($this->adult('X'), $sender, ReportCategory::Spam);
        $this->actingAs($operator)->postJson(route('safety.operator.reports.act', $other), ['action' => 'dismiss', 'reason' => 'Not spam'])->assertCreated();
        $this->assertSame(Report::DISMISSED, $other->fresh()->status);
    }

    public function test_only_operators_reach_the_moderation_screens(): void
    {
        [, $recipient, $message] = $this->adultMessage();
        $this->actingAs($recipient)->postJson(route('safety.messages.report', $message), ['category' => 'spam']);
        $report = Report::sole();

        $this->actingAs($recipient)->getJson(route('safety.operator.queue'))->assertForbidden();
        $this->actingAs($recipient)->postJson(route('safety.operator.reports.act', $report), ['action' => 'dismiss', 'reason' => 'x'])->assertForbidden();

        $operator = $this->operator();
        $this->actingAs($operator)->get(route('safety.operator.queue'))->assertOk()->assertSee('Moderation queue');
        $this->actingAs($operator)->get(route('safety.operator.reports.show', $report))->assertOk()->assertSee('Buy followers cheap');
    }

    public function test_the_unredacted_original_is_shown_only_on_the_operator_report_view(): void
    {
        $child = $this->child();
        $this->linkGuardian($child);
        $tutor = $this->adult('Tutor');
        $this->screen($tutor);
        $session = TutorSession::withoutEvents(fn () => $this->makeSession($this->makeTutor([], null, $tutor), $child, $this->makeSubject('Maths')));
        $this->actingAs($tutor)->postJson(route('safety.conversations.store'), ['context_type' => 'booking', 'context_id' => $session->id, 'body' => 'text me 0825551234'])->assertCreated();
        $this->actingAs($child)->postJson(route('safety.messages.report', Message::sole()), ['category' => 'inappropriate_contact_minor'])->assertCreated();

        $this->actingAs($this->operator())->getJson(route('safety.operator.reports.show', Report::sole()))
            ->assertOk()->assertJsonPath('report.original_body', 'text me 0825551234');
    }

    public function test_moderation_actions_are_append_only(): void
    {
        $this->actingAs($this->operator())->postJson(route('safety.operator.users.act', $this->adult()), ['action' => 'warn', 'reason' => 'x'])->assertCreated();

        $this->expectException(LogicException::class);
        ModerationAction::sole()->update(['reason' => 'changed']);
    }
}
