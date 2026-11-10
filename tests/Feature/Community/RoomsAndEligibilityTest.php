<?php

namespace Tests\Feature\Community;

use App\Domain\Academic\Contracts\AcademicScope;
use App\Domain\Community\Models\Room;
use App\Domain\Safety\Contracts\RoomDirectory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RoomsAndEligibilityTest extends TestCase
{
    use BuildsCommunity;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAcademic();
    }

    public function test_subject_rooms_come_from_academic_offerings_and_are_created_only_when_opened(): void
    {
        $teen = $this->consentedTeen();
        $level = $this->grade('grade-10');
        $offerings = app(AcademicScope::class)->offeringsAtLevel($level->id);

        $hub = $this->actingAs($teen)->getJson(route('community.api.levels.show', $level))->assertOk();
        $this->assertCount($offerings->count(), $hub->json('rooms'));
        $this->assertSame('under_18', $hub->json('audience'));
        $this->assertSame(0, Room::count(), 'Listing a hub must not create rooms.');

        $maths = $this->offering('mathematics');
        $first = $this->getJson(route('community.api.open', [$level, $maths]))->assertOk()->json('room.id');
        $second = $this->getJson(route('community.api.open', [$level, $maths]))->assertOk()->json('room.id');

        $this->assertSame($first, $second);
        $room = Room::findOrFail($first);
        $this->assertSame([$maths->curriculum_id, $level->id, $maths->subject_id, 'under_18'], [$room->curriculum_id, $room->academic_level_id, $room->subject_id, $room->audience]);
        $this->assertSame('Mathematics, Grade 10 (CAPS)', $room->displayName());

        // An offering from another level cannot be opened through this level.
        $this->getJson(route('community.api.open', [$this->grade('grade-11'), $maths]))->assertNotFound();
    }

    public function test_a_learner_lands_in_the_rooms_of_their_profile(): void
    {
        $teen = $this->consentedTeen();
        $this->profileAt($teen, 'grade-10', ['mathematics', 'physical-sciences']);

        $rooms = $this->actingAs($teen)->getJson(route('community.api.index'))->assertOk();

        $this->assertSame('Grade 10', $rooms->json('level.name'));
        $this->assertEqualsCanonicalizing(['Mathematics', 'Physical Sciences'], array_column(array_column($rooms->json('profile_rooms'), 'subject'), 'name'));
    }

    public function test_learners_under_18_and_adults_are_in_separate_rooms_for_the_same_offering(): void
    {
        $teen = $this->consentedTeen();
        $adult = $this->adult('Adult Matric Learner');
        $level = $this->grade();
        $maths = $this->offering();

        $teenRoom = $this->actingAs($teen)->getJson(route('community.api.open', [$level, $maths]))->json('room');
        $adultRoom = $this->actingAs($adult)->getJson(route('community.api.open', [$level, $maths]))->json('room');

        $this->assertSame('under_18', $teenRoom['audience']);
        $this->assertSame('adult', $adultRoom['audience']);
        $this->assertNotSame($teenRoom['id'], $adultRoom['id']);

        // A minor cannot ask for the adult room.
        $this->actingAs($teen)->getJson(route('community.api.show', $adultRoom['id']))
            ->assertForbidden()->assertJsonPath('error', 'safeguarding_denied')->assertJsonPath('reasons.0.code', 'age_band_not_allowed');
    }

    public function test_an_unscreened_adult_cannot_see_or_post_in_a_room_for_minors(): void
    {
        $room = $this->subjectRoom(Room::UNDER_18);
        $stranger = $this->adult('Unrelated Adult');
        $tutor = $this->approvedTutor('Unscreened Tutor');

        foreach ([$stranger, $tutor] as $adult) {
            $this->actingAs($adult)->getJson(route('community.api.show', $room))
                ->assertForbidden()->assertJsonPath('reasons.0.code', 'adult_screening_missing');
            $this->actingAs($adult)->postJson(route('community.api.posts.store', $room), $this->question())
                ->assertForbidden()->assertJsonPath('reasons.0.code', 'adult_screening_missing');
            $this->actingAs($adult)->postJson(route('community.api.join', $room))->assertForbidden();
            $this->actingAs($adult)->getJson(route('community.api.activity', $room))->assertNotFound();
        }

        // Asking for the minors' audience through the hub does not get an adult in either.
        $this->actingAs($stranger)->getJson(route('community.api.open', [$this->grade(), $this->offering(), 'audience' => 'under_18']))->assertForbidden();
    }

    public function test_screened_tutors_join_as_tutors_and_operators_moderate_rooms_for_minors(): void
    {
        $room = $this->subjectRoom(Room::UNDER_18);
        $tutor = $this->approvedTutor('Screened Tutor', screened: true);
        $operator = $this->operator();

        $this->actingAs($tutor)->postJson(route('community.api.join', $room))->assertOk()->assertJsonPath('membership.role', 'tutor');
        $this->actingAs($tutor)->getJson(route('community.api.show', $room))->assertOk();
        $this->actingAs($operator)->getJson(route('community.api.show', $room))->assertOk();
    }

    public function test_a_minor_without_guardian_consent_cannot_join_or_post(): void
    {
        $room = $this->subjectRoom(Room::UNDER_18);
        $limited = $this->teen('Limited Teen');

        $this->actingAs($limited)->postJson(route('community.api.posts.store', $room), $this->question())
            ->assertForbidden()->assertJsonPath('reasons.0.code', 'guardian_consent_required');
        $this->assertSame(0, $room->posts()->count());
    }

    public function test_safetys_room_directory_seam_finds_community_rooms(): void
    {
        $room = $this->subjectRoom(Room::UNDER_18);

        $found = app(RoomDirectory::class)->find($room->id);

        $this->assertInstanceOf(Room::class, $found);
        $this->assertSame(['child', 'teen'], array_map(fn ($b) => $b->value, $found->safetyAgeBands()));
        $this->assertNull(app(RoomDirectory::class)->find(999999));
    }

    public function test_room_activity_answers_304_while_nothing_changed(): void
    {
        $teen = $this->consentedTeen();
        $room = $this->subjectRoom(Room::UNDER_18);

        $first = $this->actingAs($teen)->getJson(route('community.api.activity', $room))->assertOk();
        $etag = $first->headers->get('ETag');
        $this->assertNotEmpty($etag);

        $this->getJson(route('community.api.activity', $room), ['If-None-Match' => $etag])->assertStatus(304);

        $this->postJson(route('community.api.posts.store', $room), $this->question())->assertCreated();

        $changed = $this->getJson(route('community.api.activity', $room), ['If-None-Match' => $etag])->assertOk();
        $this->assertNotSame($etag, $changed->headers->get('ETag'));
        $this->assertSame(1, $changed->json('open_questions'));
    }
}
