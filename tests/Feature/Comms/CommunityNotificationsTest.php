<?php

namespace Tests\Feature\Comms;

use App\Domain\Comms\Contracts\MinorRouting;
use App\Domain\Comms\Models\NotificationDelivery;
use App\Domain\Community\Events\AnswerPosted;
use App\Domain\Community\Models\Post;
use App\Domain\Community\Models\Room;
use App\Domain\Community\Services\Posts;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\ChannelManager;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Notification;
use Tests\Feature\Community\BuildsCommunity;
use Tests\TestCase;

/**
 * Community notices driven through the real community API and services: an answer to your question, your answer
 * accepted, your question resolved by a tutor, a request to join what you run, and a session in your study group.
 * The person who acted never hears about it; held posts are not announced until released; minors are named by
 * first name only.
 */
class CommunityNotificationsTest extends TestCase
{
    use BuildsCommunity;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAcademic();
    }

    /** The safety builders fake notifications while linking guardians: put the real ones back once people exist. */
    private function realNotifications(): void
    {
        Notification::swap(new ChannelManager($this->app));
        $routing = app(MinorRouting::class);
        if (method_exists($routing, 'forget')) {
            $routing->forget();
        }
    }

    /** @return Collection<int, DatabaseNotification> */
    private function inbox(User $user, ?string $type = null): Collection
    {
        return DatabaseNotification::query()->where('notifiable_id', $user->id)->where('notifiable_type', $user->getMorphClass())
            ->orderBy('created_at')->get()
            ->filter(fn (DatabaseNotification $n) => $type === null || ($n->data['type'] ?? null) === $type)->values();
    }

    private function ask(User $user, Room $room): int
    {
        return $this->actingAs($user)->postJson(route('community.api.posts.store', $room), $this->question())->assertCreated()->json('post.id');
    }

    private function answer(User $user, int $questionId): int
    {
        return $this->actingAs($user)->postJson(route('community.api.posts.reply', $questionId), ['body' => 'Use a squared minus b squared: (x - 3)(x + 3).'])
            ->assertCreated()->json('post.id');
    }

    public function test_the_asker_hears_about_an_answer_once_and_the_answerer_hears_nothing(): void
    {
        $room = $this->subjectRoom(Room::UNDER_18);
        $asker = $this->consentedTeen('Ayanda Khumalo');
        $helper = $this->consentedTeen('Sipho Ndlovu');
        $second = $this->consentedTeen('Lerato Mokoena');
        $this->realNotifications();

        $question = $this->ask($asker, $room);
        $this->answer($asker, $question); // answering your own question tells nobody
        $this->assertCount(0, $this->inbox($asker, 'community_answer'));

        $answer = $this->answer($helper, $question);
        $notice = $this->inbox($asker, 'community_answer')->sole();
        $this->assertSame('New answer to your question', $notice->data['title']);
        $this->assertStringContainsString('Sipho answered', $notice->data['message']);
        $this->assertStringNotContainsString('Ndlovu', $notice->data['message']); // a minor: first name only
        $this->assertSame('/rooms/posts/'.$question, $notice->data['url']);
        $this->assertSame('community', $notice->data['category']);
        $this->assertTrue($notice->data['about_minor']);
        $this->assertCount(0, $this->inbox($helper));
        $this->assertSame(1, NotificationDelivery::query()->where('event_key', 'post:'.$answer.':answered')->where('channel', 'in_app')->count());
        $this->assertNotNull(NotificationDelivery::query()->where('user_id', $asker->id)->where('type', 'community_answer')->where('channel', 'push')->first());

        // A second answer replaces the unread notice (one per question), and a re-dispatch writes nothing more.
        $this->answer($second, $question);
        AnswerPosted::dispatch($answer);
        $this->assertCount(1, $this->inbox($asker, 'community_answer'));
        $this->assertStringContainsString('Lerato answered', $this->inbox($asker, 'community_answer')->sole()->data['message']);
    }

    public function test_a_held_answer_is_announced_only_when_a_moderator_releases_it_and_children_get_it_in_app_only(): void
    {
        $room = $this->subjectRoom(Room::UNDER_18, 'mathematics', 'grade-4');
        $asker = $this->consentedChild('Naledi Zulu');
        $helper = $this->consentedChild('Kabelo Sithole');
        $operator = $this->operator();
        $this->realNotifications();

        Post::query()->whereKey($question = $this->ask($asker, $room))->update(['status' => Post::VISIBLE, 'held_reason' => null]);
        $answer = $this->answer($helper, $question);
        $this->assertSame(Post::HELD, Post::find($answer)->status);
        $this->assertCount(0, $this->inbox($asker));

        app(Posts::class)->moderate($operator, Post::find($answer), 'release');
        app(Posts::class)->moderate($operator, Post::find($answer), 'release');

        $this->assertStringContainsString('Kabelo answered', $this->inbox($asker, 'community_answer')->sole()->data['message']);
        $email = NotificationDelivery::query()->where('user_id', $asker->id)->where('type', 'community_answer')->where('channel', 'email')->sole();
        $this->assertSame(['skipped', 'child_in_app_only'], [$email->status, $email->reason]);
    }

    public function test_an_accepted_answer_tells_its_author_and_a_tutor_resolving_tells_the_asker(): void
    {
        $room = $this->subjectRoom(Room::UNDER_18);
        $asker = $this->consentedTeen('Ayanda Khumalo');
        $helper = $this->consentedTeen('Sipho Ndlovu');
        $tutor = $this->approvedTutor('Thandi Mabaso', screened: true);
        $this->realNotifications();
        $this->actingAs($tutor)->postJson(route('community.api.join', $room))->assertOk();

        // The asker accepts: the helper hears; the asker (the actor) does not.
        $question = $this->ask($asker, $room);
        $answer = $this->answer($helper, $question);
        $this->actingAs($asker)->postJson(route('community.api.posts.accept', $answer))->assertOk();
        $this->actingAs($asker)->postJson(route('community.api.posts.accept', $answer))->assertOk();
        $accepted = $this->inbox($helper, 'community_answer_accepted')->sole();
        $this->assertStringContainsString('The person who asked accepted your answer', $accepted->data['message']);
        $this->assertCount(0, $this->inbox($asker, 'community_question_resolved'));

        // A tutor accepts on another question: the helper and the asker hear, the tutor does not.
        $second = $this->ask($asker, $room);
        $answer2 = $this->answer($helper, $second);
        $this->actingAs($tutor)->postJson(route('community.api.posts.accept', $answer2))->assertOk();
        $this->assertCount(2, $this->inbox($helper, 'community_answer_accepted'));
        $resolved = $this->inbox($asker, 'community_question_resolved')->sole();
        $this->assertStringContainsString('Thandi M., a tutor in the room, accepted an answer', $resolved->data['message']);
        $this->assertCount(0, $this->inbox($tutor));

        // A tutor marks a third question resolved without an answer: only the asker hears.
        $third = $this->ask($asker, $room);
        $this->actingAs($tutor)->postJson(route('community.api.posts.resolve', $third), ['resolved' => true])->assertOk();
        $this->assertCount(2, $this->inbox($asker, 'community_question_resolved'));
        $this->assertStringContainsString('Thandi M., a tutor in the room, marked resolved', $this->inbox($asker, 'community_question_resolved')->last()->data['message']);
        $this->assertSame(1, NotificationDelivery::query()->where('user_id', $asker->id)->where('event_key', 'like', 'post:'.$third.':resolved:%')->where('channel', 'in_app')->count());
        $this->assertCount(0, $this->inbox($tutor));
    }

    public function test_the_organiser_of_an_approval_group_hears_about_each_request_in_one_notice(): void
    {
        $room = $this->subjectRoom(Room::UNDER_18);
        $owner = $this->consentedTeen('Organiser Teen');
        $first = $this->consentedTeen('Zanele Dube');
        $second = $this->consentedTeen('Musa Nkosi');
        $this->realNotifications();

        $group = $this->actingAs($owner)->postJson(route('community.api.groups.store', $room), [
            'name' => 'Paper 2 crew', 'size_cap' => 5, 'join_rule' => 'approval',
        ])->assertCreated()->json('group.id');

        $this->actingAs($first)->postJson(route('community.api.groups.join', $group))->assertJsonPath('status', 'pending');
        $this->actingAs($first)->postJson(route('community.api.groups.join', $group))->assertJsonPath('status', 'pending');
        $notice = $this->inbox($owner, 'community_join_request')->sole();
        $this->assertSame('Request to join Paper 2 crew', $notice->data['title']);
        $this->assertStringContainsString('Zanele asked to join. 1 request is waiting', $notice->data['message']);
        $this->assertStringNotContainsString('Dube', $notice->data['message']);
        $this->assertSame('/rooms/groups/'.$group, $notice->data['url']);

        $this->actingAs($second)->postJson(route('community.api.groups.join', $group))->assertJsonPath('status', 'pending');
        $this->assertStringContainsString('Musa asked to join. 2 requests are waiting', $this->inbox($owner, 'community_join_request')->sole()->data['message']);
        $this->assertCount(0, $this->inbox($first));
        $this->assertCount(0, $this->inbox($second));
    }

    public function test_the_tutor_of_an_approval_room_hears_about_a_request_and_open_rooms_send_nothing(): void
    {
        $tutor = $this->approvedTutor('Room Tutor');
        $learner = $this->adult('Anna Botha');
        $this->realNotifications();
        $base = ['curriculum_subject_level_id' => $this->offering()->id, 'audience' => Room::ADULT, 'title' => 'Matric maths workshops', 'language' => 'en', 'capacity' => 5, 'price_minor' => 0];

        $open = $this->actingAs($tutor)->postJson(route('community.api.tutor-rooms.store'), $base + ['join_rule' => 'open'])->assertCreated()->json('room.id');
        $this->actingAs($learner)->postJson(route('community.api.enrol', $open))->assertOk()->assertJsonPath('status', 'active');
        $this->assertCount(0, $this->inbox($tutor));

        $approval = $this->actingAs($tutor)->postJson(route('community.api.tutor-rooms.store'), ['title' => 'Approval workshops', 'join_rule' => 'approval'] + $base)->assertCreated()->json('room.id');
        $this->actingAs($learner)->postJson(route('community.api.enrol', $approval))->assertAccepted()->assertJsonPath('status', 'pending');
        $notice = $this->inbox($tutor, 'community_join_request')->sole();
        $this->assertSame('Request to join Approval workshops', $notice->data['title']);
        $this->assertStringContainsString('Anna B. asked to join', $notice->data['message']);
        $this->assertSame('/rooms/'.$approval, $notice->data['url']);
        $this->assertCount(0, $this->inbox($learner));
    }

    public function test_group_members_hear_about_a_scheduled_and_a_cancelled_session_but_the_organiser_does_not(): void
    {
        $room = $this->subjectRoom(Room::ADULT);
        $owner = $this->adult('Organiser');
        $mate = $this->adult('Mate');
        $other = $this->adult('Other Mate');
        $outsider = $this->adult('Outsider');
        $this->realNotifications();

        $group = $this->actingAs($owner)->postJson(route('community.api.groups.store', $room), ['name' => 'Paper 1 crew', 'size_cap' => 5, 'join_rule' => 'open'])
            ->assertCreated()->json('group.id');
        $this->actingAs($mate)->postJson(route('community.api.groups.join', $group))->assertOk();
        $this->actingAs($other)->postJson(route('community.api.groups.join', $group))->assertOk();

        $start = now('Africa/Johannesburg')->addDays(2)->setTime(16, 0);
        $session = $this->actingAs($owner)->postJson(route('community.api.groups.sessions.store', $group), [
            'title' => 'Factorising practice', 'starts_at' => $start->format('Y-m-d\TH:i'), 'timezone' => 'Africa/Johannesburg', 'duration_minutes' => 90,
        ])->assertCreated()->json('session.id');

        foreach ([$mate, $other] as $member) {
            $notice = $this->inbox($member, 'study_group_update')->sole();
            $this->assertSame('New session in Paper 1 crew', $notice->data['title']);
            $this->assertStringContainsString('Factorising practice, '.$start->format('D j M').' at 16:00 SAST (90 minutes).', $notice->data['message']);
            $this->assertSame('/rooms/groups/'.$group, $notice->data['url']);
        }
        $this->assertCount(0, $this->inbox($owner));
        $this->assertCount(0, $this->inbox($outsider));

        $this->actingAs($owner)->postJson(route('community.api.sessions.cancel', $session))->assertOk();
        $this->actingAs($owner)->postJson(route('community.api.sessions.cancel', $session))->assertOk();
        $latest = $this->inbox($mate, 'study_group_update')->sole(); // unread updates about one group collapse
        $this->assertSame('Session changed in Paper 1 crew', $latest->data['title']);
        $this->assertStringContainsString('is cancelled', $latest->data['message']);
        $this->assertSame(1, NotificationDelivery::query()->where('user_id', $mate->id)->where('event_key', 'like', '%:session_changed')->where('channel', 'in_app')->count());
        $this->assertCount(0, $this->inbox($owner));
    }
}
