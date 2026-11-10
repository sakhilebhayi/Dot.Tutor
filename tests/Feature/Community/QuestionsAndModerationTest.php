<?php

namespace Tests\Feature\Community;

use App\Domain\Academic\Translations;
use App\Domain\Community\Models\Post;
use App\Domain\Community\Models\Room;
use App\Domain\Safety\Models\Report;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

class QuestionsAndModerationTest extends TestCase
{
    use BuildsCommunity;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAcademic();
    }

    private function ask($user, Room $room, array $overrides = []): int
    {
        return $this->actingAs($user)->postJson(route('community.api.posts.store', $room), array_merge($this->question(), $overrides))
            ->assertCreated()->json('post.id');
    }

    private function answer($user, int $questionId, string $body = 'Use a squared minus b squared: (x - 3)(x + 3).'): int
    {
        return $this->actingAs($user)->postJson(route('community.api.posts.reply', $questionId), ['body' => $body])->assertCreated()->json('post.id');
    }

    public function test_the_asker_accepts_an_answer_and_the_question_is_resolved(): void
    {
        $room = $this->subjectRoom(Room::UNDER_18);
        $asker = $this->consentedTeen('Asker');
        $helper = $this->consentedTeen('Helper');
        $bystander = $this->consentedTeen('Bystander');

        $question = $this->ask($asker, $room);
        $answer = $this->answer($helper, $question);
        $this->assertSame('answer', Post::find($answer)->kind);

        $this->actingAs($bystander)->postJson(route('community.api.posts.accept', $answer))->assertForbidden()->assertJsonPath('error', 'not_allowed');

        $this->actingAs($asker)->postJson(route('community.api.posts.accept', $answer))->assertOk()
            ->assertJsonPath('question.resolved', true)->assertJsonPath('question.accepted_answer_id', $answer);

        $thread = $this->actingAs($bystander)->getJson(route('community.api.posts.show', $question))->assertOk();
        $this->assertTrue($thread->json('replies.0.accepted'));

        $this->assertSame(1, $this->actingAs($helper)->getJson(route('community.api.indicators'))->json('indicators.answers_accepted'));
        $asked = $this->actingAs($asker)->getJson(route('community.api.indicators'))->json('indicators');
        $this->assertSame(1, $asked['questions_resolved']);
        $this->assertArrayNotHasKey('likes', $asked);
        $this->assertTrue(collect($asked['milestones'])->firstWhere('text', 'Your first question was resolved')['reached']);

        // The asker can open it again; a reply cannot be an answer under a discussion.
        $this->actingAs($asker)->postJson(route('community.api.posts.resolve', $question), ['resolved' => false])->assertOk()->assertJsonPath('question.resolved', false);
    }

    public function test_a_tutor_in_the_room_can_accept_an_answer(): void
    {
        $room = $this->subjectRoom(Room::UNDER_18);
        $asker = $this->consentedTeen('Asker');
        $tutor = $this->approvedTutor('Screened Tutor', screened: true);
        $this->actingAs($tutor)->postJson(route('community.api.join', $room))->assertOk();

        $question = $this->ask($asker, $room);
        $answer = $this->answer($this->consentedTeen('Helper'), $question);

        $this->actingAs($tutor)->postJson(route('community.api.posts.accept', $answer))->assertOk()->assertJsonPath('question.resolved', true);
    }

    public function test_posts_from_children_are_held_until_a_moderator_releases_them(): void
    {
        $room = $this->subjectRoom(Room::UNDER_18, 'mathematics', 'grade-4');
        $child = $this->consentedChild();
        $classmate = $this->consentedChild('Classmate');
        $operator = $this->operator();

        $id = $this->ask($child, $room, ['title' => 'What is half of twelve?', 'body' => 'I am not sure.']);
        $post = Post::find($id);
        $this->assertSame([Post::HELD, 'pre_moderation'], [$post->status, $post->held_reason]);

        $this->actingAs($child)->getJson(route('community.api.posts.show', $id))->assertOk()->assertJsonPath('post.status', 'held');
        $this->actingAs($classmate)->getJson(route('community.api.posts.show', $id))->assertNotFound();
        $this->assertSame([], $this->actingAs($classmate)->getJson(route('community.api.show', $room))->json('threads'));

        $this->actingAs($operator)->getJson(route('community.api.moderation'))->assertOk()->assertJsonPath('held.0.id', $id);
        $this->actingAs($classmate)->postJson(route('community.api.moderation.decide', $id), ['decision' => 'release'])->assertForbidden();
        $this->actingAs($operator)->postJson(route('community.api.moderation.decide', $id), ['decision' => 'release'])->assertOk();

        $this->actingAs($classmate)->getJson(route('community.api.posts.show', $id))->assertOk()->assertJsonPath('post.status', 'visible');
    }

    public function test_contact_details_are_hidden_in_rooms_for_minors_and_kept_for_moderators_only(): void
    {
        $room = $this->subjectRoom(Room::UNDER_18);
        $teen = $this->consentedTeen();

        $id = $this->ask($teen, $room, ['body' => 'Message me on 082 123 4567 or teen@example.com and we can study.']);
        $json = $this->actingAs($teen)->getJson(route('community.api.posts.show', $id))->assertOk();

        $this->assertStringNotContainsString('082 123 4567', $json->json('post.body'));
        $this->assertStringNotContainsString('teen@example.com', $json->json('post.body'));
        $this->assertTrue($json->json('post.contact_details_hidden'));
        $this->assertStringNotContainsString('teen@example.com', $json->getContent());
        $this->assertStringContainsString('teen@example.com', (string) Post::find($id)->original_text);

        // In an adult room nothing is hidden.
        $adultRoom = $this->subjectRoom(Room::ADULT);
        $adultId = $this->ask($this->adult('Adult Learner'), $adultRoom, ['body' => 'Email me at adult@example.com']);
        $this->assertStringContainsString('adult@example.com', Post::find($adultId)->body);
    }

    public function test_a_report_reaches_the_safety_queue_and_a_child_safety_report_holds_the_post_at_once(): void
    {
        $room = $this->subjectRoom(Room::UNDER_18);
        $id = $this->ask($this->consentedTeen('Author'), $room);
        $reporter = $this->consentedTeen('Reporter');
        $reader = $this->consentedTeen('Reader');

        $this->actingAs($reporter)->postJson(route('community.api.posts.report', $id), ['category' => 'inappropriate_contact_minor', 'details' => 'Asked to meet.'])->assertCreated();

        $report = Report::query()->where('reportable_id', $id)->firstOrFail();
        $this->assertSame(Post::class, $report->reportable_type === Post::class ? Post::class : (new Post)->getMorphClass());
        $this->assertSame('critical', $report->priority->value);
        $this->assertSame(Post::HELD, Post::find($id)->status);
        $this->actingAs($reader)->getJson(route('community.api.posts.show', $id))->assertNotFound();

        // The safety queue's hide and restore work on posts (Moderatable).
        Post::find($id)->moderationRestore($this->operator());
        $this->assertSame(Post::VISIBLE, Post::find($id)->status);
    }

    public function test_posts_are_held_after_three_different_people_report_them(): void
    {
        $room = $this->subjectRoom(Room::ADULT);
        $id = $this->ask($this->adult('Author'), $room);

        foreach (['One', 'Two'] as $name) {
            $this->actingAs($this->adult($name))->postJson(route('community.api.posts.report', $id), ['category' => 'spam'])->assertCreated();
        }
        $this->assertSame(Post::VISIBLE, Post::find($id)->status);

        $same = $this->adult('Three');
        $this->actingAs($same)->postJson(route('community.api.posts.report', $id), ['category' => 'spam'])->assertCreated();
        $this->assertSame([Post::HELD, 'reported', 3], [Post::find($id)->status, Post::find($id)->held_reason, Post::find($id)->reporters_count]);
    }

    public function test_someone_who_cannot_see_the_room_cannot_report_into_it(): void
    {
        $id = $this->ask($this->consentedTeen(), $this->subjectRoom(Room::UNDER_18));

        $this->actingAs($this->adult('Outsider'))->postJson(route('community.api.posts.report', $id), ['category' => 'spam'])->assertNotFound();
        $this->assertSame(0, Report::count());
    }

    public function test_edits_keep_the_earlier_text_in_the_history(): void
    {
        $room = $this->subjectRoom(Room::ADULT);
        $author = $this->adult('Author');
        $id = $this->ask($author, $room, ['body' => 'First version of the question.']);

        $this->actingAs($this->adult('Other'))->putJson(route('community.api.posts.update', $id), ['title' => 'Changed by someone else', 'body' => 'x x'])->assertForbidden();
        $this->actingAs($author)->putJson(route('community.api.posts.update', $id), ['title' => 'How do I factorise x squared minus 9?', 'body' => 'Second version.'])->assertOk();

        $history = $this->actingAs($author)->getJson(route('community.api.posts.history', $id))->assertOk()->json('revisions');
        $this->assertSame('First version of the question.', $history[0]['body']);
        $this->assertNotNull(Post::find($id)->edited_at);
    }

    public function test_posting_is_rate_limited_in_the_service(): void
    {
        config(['community.posts_per_minute' => 2]);
        $room = $this->subjectRoom(Room::ADULT);
        $author = $this->adult('Fast Poster');

        $this->ask($author, $room);
        $this->ask($author, $room);
        $this->actingAs($author)->postJson(route('community.api.posts.store', $room), $this->question())->assertStatus(429)->assertJsonPath('error', 'too_many');
    }

    public function test_shared_material_must_be_a_published_library_item(): void
    {
        $room = $this->subjectRoom(Room::ADULT);
        $author = $this->adult('Sharer');
        $published = $this->libraryResource($author);
        $pending = $this->libraryResource($author, 'pending_review');

        $this->actingAs($author)->postJson(route('community.api.posts.store', $room), ['kind' => 'material', 'title' => 'My summary notes', 'body' => 'Covers factorising.', 'library_resource_id' => $pending->id])
            ->assertStatus(422)->assertJsonPath('error', 'resource_not_available');
        $this->actingAs($author)->postJson(route('community.api.posts.store', $room), ['kind' => 'material', 'title' => 'My summary notes', 'body' => 'Covers factorising.', 'library_resource_id' => $published->id])
            ->assertCreated()->assertJsonPath('post.library_resource_id', $published->id);

        $material = $this->actingAs($author)->getJson(route('community.api.show', [$room, 'tab' => 'material']))->assertOk();
        $this->assertSame($published->id, $material->json('library.0.id'));
        $this->assertSame(1, $this->actingAs($author)->getJson(route('community.api.indicators'))->json('indicators.resources_contributed'));
    }

    public function test_posts_are_translatable_through_the_ai_endpoint_with_the_room_rule_as_the_view_policy(): void
    {
        $room = $this->subjectRoom(Room::UNDER_18);
        $id = $this->ask($this->consentedTeen(), $room, ['original_locale' => 'zu']);
        $post = Post::find($id);

        $this->assertArrayHasKey('community.post', app(Translations::class)->types());
        $this->assertSame('zu', app(Translations::class)->originalLocale($post));
        $this->assertTrue(Gate::forUser($this->consentedTeen('Classmate'))->allows('view', $post));
        $this->assertFalse(Gate::forUser($this->adult('Outsider'))->allows('view', $post));
    }

    public function test_room_language_labels_list_the_languages_used_and_filter_threads(): void
    {
        $room = $this->subjectRoom(Room::ADULT);
        $author = $this->adult('Author');
        $this->ask($author, $room, ['original_locale' => 'zu', 'title' => 'Ngicela usizo nge-algebra']);
        $this->ask($author, $room, ['original_locale' => 'en']);

        $all = $this->actingAs($author)->getJson(route('community.api.show', $room))->assertOk();
        $this->assertEqualsCanonicalizing(['en', 'zu'], $all->json('languages'));
        $this->assertCount(1, $this->getJson(route('community.api.show', [$room, 'lang' => 'zu']))->json('threads'));
    }
}
