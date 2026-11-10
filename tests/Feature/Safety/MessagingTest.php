<?php

namespace Tests\Feature\Safety;

use App\Domain\Safety\Models\Conversation;
use App\Domain\Safety\Models\ConversationParticipant;
use App\Domain\Safety\Models\Message;
use App\Domain\Safety\Models\MessageAttachment;
use App\Domain\Safety\Services\ContactRedactor;
use App\Models\TutorSession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class MessagingTest extends TestCase
{
    use BuildsSafetyState;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('safety');
    }

    /** @return array{0: User, 1: User, 2: User, 3: TutorSession} child, guardian, tutor, booking */
    private function childBooking(bool $screened = true): array
    {
        $child = $this->child('Lerato');
        $guardian = $this->adult('Mother');
        $this->linkGuardian($child, $guardian);
        $tutor = $this->adult('Tutor');

        if ($screened) {
            $this->screen($tutor);
        }

        $profile = $this->makeTutor([], null, $tutor);
        $subject = $this->makeSubject('Maths');
        $session = TutorSession::withoutEvents(fn () => $this->makeSession($profile, $child, $subject));

        return [$child, $guardian, $tutor, $session];
    }

    public function test_an_adult_cannot_open_a_direct_conversation_with_a_child(): void
    {
        $child = $this->child();
        $this->linkGuardian($child);
        $adult = $this->adult('Stranger');
        $this->screen($adult);

        $this->actingAs($adult)->postJson(route('safety.conversations.store'), [
            'context_type' => 'direct', 'recipient_ids' => [$child->id], 'body' => 'Hi',
        ])->assertForbidden()->assertJsonPath('error', 'safeguarding_denied')->assertJsonPath('reasons.0.code', 'minor_needs_context');

        $this->assertSame(0, Conversation::count());
    }

    public function test_adults_can_message_each_other_directly(): void
    {
        $a = $this->adult('Asha');
        $b = $this->adult('Bongani');

        $this->actingAs($a)->postJson(route('safety.conversations.store'), [
            'context_type' => 'direct', 'recipient_ids' => [$b->id], 'body' => 'Call me on 082 555 1234',
        ])->assertCreated()->assertJsonPath('message.redacted', false)->assertJsonPath('message.body', 'Call me on 082 555 1234');
    }

    public function test_a_child_and_a_screened_tutor_talk_inside_their_booking_with_the_guardian_copied(): void
    {
        [$child, $guardian, $tutor, $session] = $this->childBooking();

        $id = $this->actingAs($tutor)->postJson(route('safety.conversations.store'), [
            'context_type' => 'booking', 'context_id' => $session->id, 'body' => 'Bring your homework.',
        ])->assertCreated()->json('conversation_id');

        $conversation = Conversation::findOrFail($id);
        $this->assertTrue($conversation->involves_minor);
        $this->assertSame(ConversationParticipant::GUARDIAN, $conversation->participantFor($guardian)->role);

        $this->actingAs($guardian)->getJson(route('safety.conversations.show', $id))
            ->assertOk()->assertJsonPath('messages.0.body', 'Bring your homework.')->assertJsonPath('conversation.your_role', 'guardian');

        $this->actingAs($child)->postJson(route('safety.conversations.messages.store', $id), ['body' => 'Ok!'])->assertCreated();
    }

    public function test_an_unscreened_tutor_cannot_message_a_child_even_inside_a_booking(): void
    {
        [, , $tutor, $session] = $this->childBooking(screened: false);

        $this->actingAs($tutor)->postJson(route('safety.conversations.store'), [
            'context_type' => 'booking', 'context_id' => $session->id, 'body' => 'Hello',
        ])->assertForbidden()->assertJsonPath('reasons.0.code', 'tutor_screening_missing');
    }

    public function test_only_the_two_parties_of_a_booking_can_open_its_conversation(): void
    {
        [, , , $session] = $this->childBooking();

        $this->actingAs($this->adult('Other'))->postJson(route('safety.conversations.store'), [
            'context_type' => 'booking', 'context_id' => $session->id, 'body' => 'Hello',
        ])->assertForbidden();
    }

    public function test_contact_details_are_hidden_with_a_minor_and_kept_as_evidence(): void
    {
        [$child, , $tutor, $session] = $this->childBooking();

        $response = $this->actingAs($tutor)->postJson(route('safety.conversations.store'), [
            'context_type' => 'booking', 'context_id' => $session->id,
            'body' => 'WhatsApp me on +27 82 555 1234 or mail tutor@example.com, insta: cool_tutor99, https://wa.me/27825551234',
        ])->assertCreated();

        $body = $response->json('message.body');
        $this->assertStringNotContainsString('555', $body);
        $this->assertStringNotContainsString('tutor@example.com', $body);
        $this->assertStringNotContainsString('cool_tutor99', $body);
        $this->assertStringNotContainsString('wa.me', $body);
        $this->assertSame(ContactRedactor::NOTE, $response->json('message.redaction_note'));

        $message = Message::sole();
        $this->assertStringContainsString('tutor@example.com', $message->original_body, 'the original is kept for safeguarding staff');
        $this->assertStringNotContainsString('tutor@example.com', (string) DB::table('safety_messages')->value('original_body'), 'stored encrypted');
        $this->assertArrayNotHasKey('original_body', $message->toArray());

        $this->actingAs($child)->getJson(route('safety.conversations.show', $message->conversation_id))
            ->assertJsonMissing(['original_body' => $message->original_body]);
    }

    public function test_someone_outside_the_conversation_cannot_read_or_post(): void
    {
        [, , $tutor, $session] = $this->childBooking();
        $id = $this->actingAs($tutor)->postJson(route('safety.conversations.store'), ['context_type' => 'booking', 'context_id' => $session->id, 'body' => 'Hi'])->json('conversation_id');
        $outsider = $this->adult('Outsider');

        $this->actingAs($outsider)->getJson(route('safety.conversations.show', $id))->assertForbidden();
        $this->actingAs($outsider)->postJson(route('safety.conversations.messages.store', $id), ['body' => 'x'])->assertForbidden();
        $this->actingAs($this->operator())->getJson(route('safety.conversations.show', $id))->assertForbidden();
    }

    public function test_a_limited_minor_can_still_reach_support(): void
    {
        $child = $this->child();

        $id = $this->actingAs($child)->postJson(route('safety.conversations.store'), [
            'context_type' => 'support', 'subject' => 'Help', 'body' => 'Someone is bothering me',
        ])->assertCreated()->json('conversation_id');

        $operator = $this->operator();
        $this->actingAs($operator)->getJson(route('safety.conversations.show', $id))->assertOk();
        $this->actingAs($operator)->postJson(route('safety.conversations.messages.store', $id), ['body' => 'We are here. Tell us more.'])->assertCreated();
        $this->assertSame(ConversationParticipant::OPERATOR, Conversation::find($id)->participantFor($operator)->role);
    }

    public function test_a_guardian_linked_later_is_added_to_existing_conversations(): void
    {
        $teen = $this->teen();
        $first = $this->adult('First');
        $this->linkGuardian($teen, $first);
        $tutor = $this->adult('Tutor');
        $this->screen($tutor);
        $session = TutorSession::withoutEvents(fn () => $this->makeSession($this->makeTutor([], null, $tutor), $teen, $this->makeSubject('Maths')));
        $id = $this->actingAs($tutor)->postJson(route('safety.conversations.store'), ['context_type' => 'booking', 'context_id' => $session->id, 'body' => 'Hi'])->json('conversation_id');

        $second = $this->adult('Second');
        $this->linkGuardian($teen, $second, learnerAge: 15);

        $this->actingAs($second)->getJson(route('safety.conversations.show', $id))->assertOk();
    }

    public function test_a_revoked_guardian_loses_access(): void
    {
        [$child, $guardian, $tutor, $session] = $this->childBooking();
        $id = $this->actingAs($tutor)->postJson(route('safety.conversations.store'), ['context_type' => 'booking', 'context_id' => $session->id, 'body' => 'Hi'])->json('conversation_id');
        $link = \App\Domain\Safety\Models\GuardianLink::query()->where('guardian_id', $guardian->id)->sole();

        $this->actingAs($guardian)->postJson(route('safety.guardian.links.revoke', $link))->assertOk();

        $this->actingAs($guardian)->getJson(route('safety.conversations.show', $id))->assertForbidden();
    }

    public function test_messages_are_rate_limited_for_every_caller(): void
    {
        config(['safety.messaging.per_minute' => 3]);
        $a = $this->adult('Asha');
        $b = $this->adult('Bongani');
        $id = $this->actingAs($a)->postJson(route('safety.conversations.store'), ['context_type' => 'direct', 'recipient_ids' => [$b->id], 'body' => '1'])->json('conversation_id');

        $this->actingAs($a)->postJson(route('safety.conversations.messages.store', $id), ['body' => '2'])->assertCreated();
        $this->actingAs($a)->postJson(route('safety.conversations.messages.store', $id), ['body' => '3'])->assertCreated();
        $this->actingAs($a)->postJson(route('safety.conversations.messages.store', $id), ['body' => '4'])->assertStatus(429);
    }

    public function test_attachments_are_sniffed_stored_privately_and_served_only_to_participants(): void
    {
        $a = $this->adult('Asha');
        $b = $this->adult('Bongani');
        $png = UploadedFile::fake()->image('notes.png', 20, 20);

        $response = $this->actingAs($a)->post(route('safety.conversations.store'), [
            'context_type' => 'direct', 'recipient_ids' => [$b->id], 'body' => 'See attached', 'attachments' => [$png],
        ], ['Accept' => 'application/json'])->assertCreated();

        $attachment = MessageAttachment::sole();
        Storage::disk('safety')->assertExists($attachment->path);
        $this->assertSame('image/png', $attachment->mime);

        $this->actingAs($b)->get($response->json('message.attachments.0.url'))->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->actingAs($this->adult('Eve'))->get($response->json('message.attachments.0.url'))->assertForbidden();

        $fake = UploadedFile::fake()->createWithContent('evil.pdf', '<?php echo 1;');
        $this->actingAs($a)->post(route('safety.conversations.messages.store', $response->json('conversation_id')), ['attachments' => [$fake]], ['Accept' => 'application/json'])
            ->assertUnprocessable();
        $this->assertSame(1, MessageAttachment::count());
    }

    public function test_unread_counts_follow_read_state(): void
    {
        $a = $this->adult('Asha');
        $b = $this->adult('Bongani');
        $id = $this->actingAs($a)->postJson(route('safety.conversations.store'), ['context_type' => 'direct', 'recipient_ids' => [$b->id], 'body' => 'Hello'])->json('conversation_id');

        $this->actingAs($b)->getJson(route('safety.conversations.index'))->assertJsonPath('conversations.0.unread', 1);
        $this->actingAs($b)->getJson(route('safety.conversations.show', $id))->assertOk();
        $this->travel(1)->seconds();
        $this->actingAs($b)->getJson(route('safety.conversations.index'))->assertJsonPath('conversations.0.unread', 0);
    }

    public function test_the_same_booking_reuses_one_conversation(): void
    {
        [, , $tutor, $session] = $this->childBooking();

        $first = $this->actingAs($tutor)->postJson(route('safety.conversations.store'), ['context_type' => 'booking', 'context_id' => $session->id, 'body' => 'One'])->json('conversation_id');
        $second = $this->actingAs($tutor)->postJson(route('safety.conversations.store'), ['context_type' => 'booking', 'context_id' => $session->id, 'body' => 'Two'])->json('conversation_id');

        $this->assertSame($first, $second);
    }

    public function test_room_conversations_need_a_room(): void
    {
        $this->actingAs($this->adult())->postJson(route('safety.conversations.store'), [
            'context_type' => 'room', 'context_id' => 1, 'recipient_ids' => [2], 'body' => 'x',
        ])->assertNotFound();
    }

    public function test_the_web_views_render(): void
    {
        [$child, $guardian, $tutor, $session] = $this->childBooking();
        $this->actingAs($tutor)->post(route('safety.conversations.store'), ['context_type' => 'booking', 'context_id' => $session->id, 'body' => 'Hi there'])
            ->assertRedirect();

        $id = Conversation::sole()->id;
        $this->actingAs($child)->get(route('safety.conversations.index'))->assertOk()->assertSee('Messages');
        $this->actingAs($child)->get(route('safety.conversations.show', $id))->assertOk()->assertSee('Hi there')->assertSee('Report this message');
        $this->actingAs($child)->get(route('safety.account'))->assertOk()->assertSee('Mother');
        $this->actingAs($guardian)->get(route('safety.guardian.dashboard'))->assertOk()->assertSee('Lerato');
    }
}
