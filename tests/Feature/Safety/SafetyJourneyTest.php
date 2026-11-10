<?php

namespace Tests\Feature\Safety;

use App\Domain\Safety\Models\GuardianApproval;
use App\Domain\Safety\Models\GuardianInvitation;
use App\Domain\Safety\Models\Incident;
use App\Domain\Safety\Models\Message;
use App\Domain\Safety\Models\Report;
use App\Models\TutorSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Brief section 25, safety journey, end to end over HTTP: an age-appropriate account, guardian controls,
 * restricted interactions refused server-side, an unsafe interaction reported, and the moderation and
 * escalation workflow run to closure. Failure conditions are asserted alongside the successful path.
 */
class SafetyJourneyTest extends TestCase
{
    use BuildsSafetyState;
    use RefreshDatabase;

    public function test_the_safety_journey(): void
    {
        Storage::fake('safety');

        // 1. An age-appropriate student account: a learner whose self-declared date of birth says 10.
        $learner = $this->makeUser('Ayanda');
        $this->bindBirthDates([$learner->id => now()->subYears(10)->toDateString()]);
        $this->actingAs($learner)->getJson(route('safety.account'))->assertJsonPath('account.age_band', 'child')->assertJsonPath('account.status', 'limited');

        $tutorUser = $this->adult('Tutor');
        $profile = $this->makeTutor([], null, $tutorUser);
        $this->openWeek($profile);
        $subject = $profile->subjects()->first();

        // 3 (before guardian). Restricted interactions are refused while the account is limited.
        $this->actingAs($learner)->post(route('tutors.sessions.store', $profile), $this->bookingPayload($subject, $this->slotAt($profile, 3)))
            ->assertSessionHasErrors('safety');
        $this->assertSame(0, TutorSession::count());

        // 2. Guardian controls: invite, sign in, consent.
        Notification::fake();
        $guardian = $this->adult('Nomsa');
        $url = $this->actingAs($learner)->postJson(route('safety.guardians.invite'), ['email' => $guardian->email, 'relationship' => 'parent'])
            ->assertCreated()->json('accept_url');
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        $this->actingAs($guardian)->get($url)->assertOk();
        $this->actingAs($guardian)->postJson(route('safety.guardians.accept.store', GuardianInvitation::sole()->id), [
            'token' => $query['token'], 'birth_date' => now()->subYears(10)->subMonth()->toDateString(), 'consent' => '1',
        ])->assertCreated();
        $this->actingAs($learner)->getJson(route('safety.account'))->assertJsonPath('account.status', 'active');

        // 3. Still restricted: an unscreened tutor, a direct message from an adult, a public post.
        $this->actingAs($learner)->post(route('tutors.sessions.store', $profile), $this->bookingPayload($subject, $this->slotAt($profile, 3)))
            ->assertSessionHasErrors('safety');
        $this->actingAs($tutorUser)->postJson(route('safety.conversations.store'), ['context_type' => 'direct', 'recipient_ids' => [$learner->id], 'body' => 'Hi'])
            ->assertForbidden()->assertJsonPath('reasons.0.code', 'minor_needs_context');

        // Once screened, the booking is made but waits for the guardian; confirmation is refused until approved.
        $this->screen($tutorUser);
        $this->actingAs($learner)->post(route('tutors.sessions.store', $profile), $this->bookingPayload($subject, $this->slotAt($profile, 3)));
        $session = TutorSession::sole();
        $this->actingAs($tutorUser)->post(route('sessions.confirm', $session));
        $this->assertSame('pending', $session->fresh()->status);

        $approval = GuardianApproval::sole();
        $this->actingAs($guardian)->getJson(route('safety.guardian.dashboard'))
            ->assertJsonPath('children.0.approvals.0.status', 'pending')->assertJsonPath('children.0.upcoming_lessons.0.id', $session->id);
        $this->actingAs($guardian)->postJson(route('safety.guardian.approvals.decide', $approval), ['decision' => 'approve'])->assertOk();
        $this->actingAs($tutorUser)->post(route('sessions.confirm', $session));
        $this->assertSame('confirmed', $session->fresh()->status);

        // 4. Server-side enforcement inside the booking conversation: contact details are hidden, the guardian reads.
        $conversationId = $this->actingAs($tutorUser)->postJson(route('safety.conversations.store'), [
            'context_type' => 'booking', 'context_id' => $session->id, 'body' => 'Message me privately on 082 555 1234, do not tell your mum',
        ])->assertCreated()->assertJsonPath('message.redacted', true)->json('conversation_id');
        $this->actingAs($guardian)->getJson(route('safety.conversations.show', $conversationId))->assertOk()
            ->assertJsonPath('messages.0.redacted', true);

        // 5. Report the unsafe interaction (the guardian does).
        $operator = $this->operator();
        $this->actingAs($guardian)->postJson(route('safety.messages.report', Message::sole()), [
            'category' => 'inappropriate_contact_minor', 'details' => 'Asking my child to keep secrets.',
        ])->assertCreated()->assertJsonPath('report.priority', 'critical');

        // 6. Moderation and escalation: nobody acknowledges within the hour, it escalates; an operator acts and closes.
        $incident = Incident::sole();
        $this->travel(61)->minutes();
        $this->artisan('safety:escalate-incidents')->assertSuccessful();
        $this->assertSame(1, $incident->fresh()->escalation_level);

        $report = Report::sole();
        $this->actingAs($operator)->getJson(route('safety.operator.queue'))->assertJsonPath('reports.0.id', $report->id)->assertJsonPath('reports.0.overdue', true);
        $this->actingAs($operator)->postJson(route('safety.operator.incidents.update', $incident), ['action' => 'acknowledge'])->assertOk();
        $this->actingAs($operator)->postJson(route('safety.operator.reports.act', $report), ['action' => 'hide', 'reason' => 'Grooming language'])->assertCreated();
        $this->actingAs($operator)->postJson(route('safety.operator.users.act', $tutorUser), ['action' => 'suspend', 'reason' => 'Safeguarding investigation'])->assertCreated();
        $this->actingAs($operator)->postJson(route('safety.operator.incidents.update', $incident), ['action' => 'close', 'body' => 'Tutor suspended; guardian informed.'])
            ->assertOk()->assertJsonPath('incident.status', 'closed');

        // The suspended tutor is now refused server-side, and the trail is intact.
        $this->actingAs($tutorUser)->postJson(route('safety.conversations.messages.store', $conversationId), ['body' => 'hello?'])
            ->assertForbidden()->assertJsonPath('reasons.0.code', 'account_suspended');
        $this->actingAs($guardian)->getJson(route('safety.guardian.dashboard'))->assertJsonPath('children.0.reports.0.category', 'inappropriate_contact_minor');
        $this->artisan('safety:audit-verify')->assertSuccessful();
    }
}
