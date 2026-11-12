<?php

namespace Tests\Feature\Community;

use App\Domain\Community\Models\Post;
use App\Domain\Community\Models\Room;
use App\Domain\Community\Models\StudyGroup;
use App\Domain\Community\Services\Recommendations;
use App\Domain\Learning\Models\Course;
use App\Models\TutorProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CommunityScreensTest extends TestCase
{
    use BuildsCommunity;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAcademic();
    }

    public function test_the_learner_journey_screens_render_in_the_learner_shell(): void
    {
        $teen = $this->consentedTeen('Thandi Mokoena');
        $this->profileAt($teen, 'grade-10', ['mathematics']);
        $room = $this->subjectRoom(Room::UNDER_18);

        // 1. Enter the academic room. 2. Select a subject.
        $this->actingAs($teen)->get(route('rooms.index'))->assertOk()->assertSee('My rooms')->assertSee('Grade 10, CAPS')->assertSee('Mathematics')
            ->assertSee('There are no likes, followers or rankings here.');
        $this->get(route('rooms.levels.show', $this->grade()))->assertOk()->assertSee('Subject rooms');
        $this->get(route('rooms.open', [$this->grade(), $this->offering()]))->assertRedirect(route('rooms.show', $room));

        // 3. Ask a question.
        $this->get(route('rooms.ask', $room))->assertOk()->assertSee('Ask a question');
        $this->post(route('rooms.posts.store', $room), $this->question())->assertRedirect();
        $question = Post::query()->where('kind', 'question')->firstOrFail();

        // 4. Share study material (the library item must exist first; learning owns files).
        $resource = $this->libraryResource($teen);
        $this->get(route('rooms.ask', [$room, 'kind' => 'material']))->assertOk()->assertSee($resource->title);

        // 5. Receive useful answers.
        $helper = $this->consentedTeen('Sipho Ndlovu');
        $this->actingAs($helper)->post(route('rooms.posts.reply', $question), ['body' => 'Difference of two squares: (x - 3)(x + 3).'])->assertRedirect();
        $this->actingAs($teen)->get(route('rooms.posts.show', $question))->assertOk()->assertSee('Accept this answer')->assertSee('Sipho N.')->assertDontSee('Ndlovu');

        // 6. Find relevant tutors and courses. 7. Report inappropriate content.
        foreach (['questions', 'discussions', 'material', 'tutors', 'groups'] as $tab) {
            $this->get(route('rooms.show', [$room, 'tab' => $tab]))->assertOk();
        }
        $this->get(route('rooms.show', [$room, 'tab' => 'tutors']))->assertSee('Request tutoring help');
        $this->actingAs($helper)->post(route('rooms.posts.report', $question), ['category' => 'spam'])->assertRedirect();
    }

    public function test_group_listing_teach_and_moderation_screens_render(): void
    {
        $tutor = $this->approvedTutor('Lerato Dlamini');
        $this->actingAs($tutor)->get(route('teach.rooms'))->assertOk()->assertSee('Start a room');
        $this->post(route('rooms.tutor-rooms.store'), [
            'curriculum_subject_level_id' => $this->offering()->id, 'audience' => 'adult', 'title' => 'Saturday maths workshops',
            'join_rule' => 'open', 'price_rand' => '120.00', 'capacity' => 10,
        ])->assertRedirect();
        $tutorRoom = Room::query()->where('kind', 'tutor')->firstOrFail();
        $this->assertSame(12000, $tutorRoom->price_minor);
        $this->get(route('rooms.show', [$tutorRoom, 'tab' => 'groups']))->assertOk()->assertSee('Workshops');

        $learner = $this->adult('Adult Learner');
        $this->actingAs($learner)->get(route('rooms.show', $tutorRoom))->assertOk()->assertSee('Pay and join')->assertSee('120.00');

        $room = $this->subjectRoom(Room::ADULT);
        $this->post(route('rooms.groups.store', $room), ['name' => 'Evening study', 'size_cap' => 4, 'join_rule' => 'open'])->assertRedirect();
        $group = StudyGroup::firstOrFail();
        $this->get(route('rooms.groups.show', $group))->assertOk()->assertSee('Next session')->assertSee('Revision list');

        $child = $this->consentedChild();
        $this->actingAs($child)->post(route('rooms.posts.store', $this->subjectRoom(Room::UNDER_18, 'mathematics', 'grade-4')), $this->question('What is half of twelve please?', 'Not sure.'))->assertRedirect();
        $this->actingAs($this->operator())->get(route('rooms.moderation'))->assertOk()->assertSee('What is half of twelve please?');
        $this->actingAs($learner)->get(route('rooms.moderation'))->assertForbidden();
    }

    public function test_tutor_recommendations_rank_by_fit_and_only_screened_tutors_reach_minor_rooms(): void
    {
        $room = $this->subjectRoom(Room::UNDER_18);
        $viewer = $this->consentedTeen();
        $screened = $this->approvedTutor('Screened Tutor', screened: true);
        $unscreened = $this->approvedTutor('Unscreened Tutor');
        foreach ([$screened, $unscreened] as $tutor) {
            TutorProfile::where('user_id', $tutor->id)->first()->subjects()->attach($this->subject('mathematics')->id);
        }

        $names = array_column(app(Recommendations::class)->tutorsFor($room, $viewer), 'user_id');
        $this->assertSame([$screened->id], $names);

        $adultRoom = $this->subjectRoom(Room::ADULT);
        $this->assertCount(2, app(Recommendations::class)->tutorsFor($adultRoom, $this->adult('Adult')));
    }

    public function test_courses_for_the_room_are_published_ones_for_its_subject_and_level(): void
    {
        $room = $this->subjectRoom(Room::ADULT);
        $owner = $this->approvedTutor('Course Author');
        $base = ['owner_id' => $owner->id, 'subject_id' => $room->subject_id, 'original_locale' => 'en', 'pricing' => 'free', 'price_minor' => 0, 'currency' => 'ZAR', 'description' => 'x'];
        Course::create($base + ['slug' => 'a', 'title' => 'Grade 10 algebra', 'level_id' => $room->academic_level_id, 'curriculum_id' => $room->curriculum_id, 'status' => 'published', 'published_at' => now()]);
        Course::create($base + ['slug' => 'b', 'title' => 'Draft course', 'level_id' => $room->academic_level_id, 'status' => 'draft']);
        Course::create($base + ['slug' => 'c', 'title' => 'Grade 12 calculus', 'level_id' => $this->grade('grade-12')->id, 'status' => 'published', 'published_at' => now()]);

        $titles = app(Recommendations::class)->coursesFor($room, $this->adult('Viewer'))->map(fn ($row) => $row['course']->title)->all();
        $this->assertSame(['Grade 10 algebra'], $titles);
    }
}
