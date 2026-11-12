<?php

namespace Tests\Feature\Student;

use App\Domain\Comms\CommsMessage;
use App\Domain\Comms\NotificationRouter;
use App\Domain\Learning\Models\FlashcardSet;
use App\Domain\Student\Support\FileKind;
use App\Models\TutorSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Acceptance\BuildsJourneys;
use Tests\TestCase;

/**
 * R-47 polish on the learner screens (docs/R47_POLISH_LEARNER.md): the booking day strip, shared match reasons, the
 * flashcard editor, the last lesson on home, quiet notification rows, plain file types and the young step's words.
 */
class LearnerPolishTest extends TestCase
{
    use BuildsJourneys;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpWorld();
    }

    public function test_the_booking_page_shows_a_day_strip_and_one_submit_beside_the_price(): void
    {
        [, , $offeringId] = $this->tutorReadyToTeach('Thandeka Mokoena');
        $learner = $this->person('Sipho Dlamini');
        $this->learnerProfile($learner, 19);

        $html = $this->actingAs($learner)->get(route('learn.book', $offeringId))->assertOk()->getContent();
        $this->assertGreaterThanOrEqual(7, substr_count($html, 'name="day_pick"'), 'a day strip');
        $this->assertSame(1, preg_match_all('/id="day-\d+"[^>]*checked/', $html), 'one day is chosen');
        $this->assertSame(1, substr_count($html, 'form="book-form"'), 'one submit, outside the form, beside the price');
        $this->assertStringContainsString('id="book-form"', $html);
        $this->assertStringContainsString('Request this lesson', $html);
    }

    public function test_reasons_every_tutor_shares_are_said_once(): void
    {
        $this->tutorReadyToTeach('Thandeka Mokoena');
        $this->tutorReadyToTeach('Zanele Mthembu');
        $learner = $this->person('Sipho Dlamini');
        $this->learnerProfile($learner, 19);

        $html = $this->actingAs($learner)->get(route('marketplace.find', [
            'subject_id' => $this->subject('mathematics')->id, 'curriculum_id' => $this->level('za-caps', 'grade-11')->curriculum_id,
            'level_id' => $this->level('za-caps', 'grade-11')->id,
        ]))->assertOk()->assertSee('Both tutors')->assertSee('Follows CAPS, your curriculum.')
            ->assertDontSee('Curriculum and Assessment Policy Statement curriculum')->getContent();
        $this->assertSame(1, substr_count($html, 'Follows CAPS, your curriculum.'), 'a shared reason appears once, not on every card');
    }

    public function test_flashcards_are_written_in_two_fields_and_empty_rows_are_dropped(): void
    {
        $learner = $this->person('Card Learner');
        $this->actingAs($learner)->get(route('practice.index'))->assertOk()
            ->assertSee('name="card[0][front]"', false)->assertSee('name="card[0][back]"', false)->assertSee('Add another card')
            ->assertDontSee('name="cards_text"', false);

        $this->actingAs($learner)->post(route('practice.cards.store'), [
            'title' => 'Capitals', 'subject_id' => '',
            'card' => [['front' => 'Capital of Lesotho', 'back' => 'Maseru'], ['front' => '', 'back' => ''], ['front' => '7 x 8', 'back' => '56']],
        ])->assertRedirect();
        $set = FlashcardSet::query()->where('owner_id', $learner->id)->firstOrFail();
        $this->assertSame(2, $set->cards()->count());

        $this->actingAs($learner)->post(route('practice.cards.store'), ['title' => 'Empty', 'card' => [['front' => '', 'back' => '']]])
            ->assertSessionHasErrors('cards');
    }

    public function test_home_shows_the_last_lesson_with_book_again(): void
    {
        [, $profile, $offeringId] = $this->tutorReadyToTeach('Thandeka Mokoena');
        $learner = $this->person('Sipho Dlamini');
        $this->learnerProfile($learner, 19);
        $id = $this->requestLesson($learner, $offeringId, $profile)->assertCreated()->json('data.id');
        TutorSession::query()->whereKey($id)->update(['status' => 'completed', 'starts_at' => now()->subDays(2)]);

        $this->actingAs($learner)->get(route('learn.home'))->assertOk()
            ->assertSee('Your last lesson')->assertSee('with Thandeka Mokoena')->assertSee('Book again')
            ->assertSee(route('learn.book', $offeringId), false);
    }

    public function test_a_notification_opens_from_its_title_without_a_row_of_green_buttons(): void
    {
        $user = $this->person('Inbox Learner');
        app(NotificationRouter::class)->send(new CommsMessage($user, 'lesson_confirmed', 'p1', 'Lesson confirmed with Ms Khumalo', 'Your Algebra lesson is on Thu.', '/my/bookings'));

        $html = $this->actingAs($user)->get('/comms/inbox')->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/<button type="submit" form="comms-open-[^"]+" class="comms-item__open">Lesson confirmed with Ms Khumalo/', $html);
        $this->assertStringNotContainsString('>Open</span>', $html);
    }

    public function test_file_types_are_named_in_plain_words(): void
    {
        $this->assertSame('PDF', FileKind::label('application/pdf'));
        $this->assertSame('Word document', FileKind::label('application/vnd.openxmlformats-officedocument.wordprocessingml.document'));
        $this->assertSame('HEIC image', FileKind::label('image/heic'));
        $this->assertSame('File', FileKind::label(null));
    }

    public function test_the_young_step_reads_for_a_ten_year_old(): void
    {
        [, , $offeringId] = $this->tutorReadyToTeach('Thandeka Mokoena');
        $child = $this->person('Lerato Young');
        $this->learnerProfile($child, 10, 'za-caps', 'grade-5', ['zu']);

        $this->actingAs($child)->get(route('learn.home'))->assertOk()
            ->assertSee('data-td-step="young"', false)->assertSee('Maths, Grade 5')
            ->assertDontSee('AI assistant can reword')->assertDontSee('Not AI-generated');
        $this->actingAs($child)->get(route('learn.book', $offeringId))->assertOk()
            ->assertDontSee('commission')->assertSee('Nothing is paid until your parent or guardian says yes');
    }
}
