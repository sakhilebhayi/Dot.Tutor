<?php

namespace Tests\Feature\Classroom;

use App\Domain\Classroom\Events\ClassroomEnded;
use App\Domain\Classroom\Events\ClassroomOpened;
use App\Domain\Classroom\Events\FollowUpAssigned;
use App\Domain\Classroom\Events\LessonStartingSoon;
use App\Domain\Classroom\Events\LessonSummaryPublished;
use App\Domain\Classroom\Services\ClassroomService;
use App\Domain\Learning\Models\Assessment;
use App\Domain\Learning\Models\Assignment;
use App\Domain\Learning\Models\Question;
use App\Domain\Learning\Models\QuestionBank;
use App\Enums\SessionStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/** The lesson record: summary, private notes, completion through the marketplace, follow-up work, events for comms. */
class AfterLessonTest extends TestCase
{
    use BuildsClassroom;
    use RefreshDatabase;

    public function test_the_tutor_completes_the_lesson_through_the_marketplace_flow(): void
    {
        [$tutor, $profile] = $this->tutor();
        $learner = $this->adult('Learner');
        $session = $this->lesson($profile, $learner, minutes: 5);

        $this->actingAs($learner)->post(route('classroom.complete', $session))->assertForbidden();
        $this->actingAs($tutor)->from(route('classroom.after', $session))->post(route('classroom.complete', $session))
            ->assertRedirect()->assertSessionHasErrors('classroom'); // not started yet: the marketplace refuses

        $this->travel(10)->minutes();
        $this->joinAs($tutor, $session, 't')->assertOk();
        $this->assertSame(SessionStatus::InProgress, $session->fresh()->statusEnum());
        $this->actingAs($tutor)->post(route('classroom.complete', $session))->assertRedirect()->assertSessionHas('success', 'The lesson is marked as completed.');
        $this->assertSame(SessionStatus::Completed, $session->fresh()->statusEnum());
        $this->assertSame(1, $session->events()->where('event', 'completed')->count());

        $this->joinAs($learner, $session, 'l')->assertStatus(409)->assertJsonPath('error', 'lesson_over');
    }

    public function test_the_summary_is_private_until_published_and_publishing_emits_one_event(): void
    {
        Event::fake([LessonSummaryPublished::class]);
        [$tutor, $profile] = $this->tutor();
        $learner = $this->adult('Learner');
        $session = $this->lesson($profile, $learner, minutes: -30);

        $this->actingAs($tutor)->post(route('classroom.summary', $session), ['summary' => 'We factorised quadratics.', 'publish' => 0])->assertRedirect();
        $this->actingAs($learner)->get(route('classroom.after', $session))->assertOk()->assertSee('No summary yet')->assertDontSee('We factorised quadratics.');
        Event::assertNotDispatched(LessonSummaryPublished::class);

        $this->actingAs($tutor)->post(route('classroom.summary', $session), ['summary' => 'We factorised quadratics.', 'publish' => 1])->assertRedirect();
        $this->actingAs($tutor)->post(route('classroom.summary', $session), ['summary' => 'We factorised quadratics. Practise 4 to 9.', 'publish' => 1])->assertRedirect();
        $this->actingAs($learner)->get(route('classroom.after', $session))->assertOk()->assertSee('Practise 4 to 9.');
        Event::assertDispatchedTimes(LessonSummaryPublished::class, 1);

        $this->actingAs($learner)->post(route('classroom.summary', $session), ['summary' => 'Mine', 'publish' => 1])->assertForbidden();
    }

    public function test_private_notes_are_the_tutors_alone(): void
    {
        [$tutor, $profile] = $this->tutor();
        $learner = $this->adult('Learner');
        $session = $this->lesson($profile, $learner, minutes: -30);

        $this->actingAs($tutor)->post(route('classroom.notes', $session), ['tutor_notes' => 'Struggles with negative signs'])->assertRedirect();
        $this->actingAs($tutor)->get(route('classroom.after', $session))->assertSee('Struggles with negative signs');
        $this->actingAs($learner)->get(route('classroom.after', $session))->assertOk()->assertDontSee('Struggles with negative signs');
        $this->actingAs($learner)->post(route('classroom.notes', $session), ['tutor_notes' => 'x'])->assertForbidden();
    }

    public function test_follow_up_work_goes_through_learnings_assignment_for_learners_in_the_lesson(): void
    {
        Event::fake([FollowUpAssigned::class]);
        [$tutor, $profile] = $this->tutor();
        $a = $this->adult('Learner A');
        $b = $this->adult('Learner B');
        $session = $this->groupLesson($profile, [$a, $b], minutes: -30);
        $bank = QuestionBank::query()->create(['owner_id' => $tutor->id, 'title' => 'Quadratics', 'topic' => 'Quadratics']);
        $question = Question::query()->create(['bank_id' => $bank->id, 'type' => 'numeric', 'prompt' => 'Solve x^2 = 9, x > 0', 'answer' => ['value' => 3, 'tolerance' => 0], 'marks' => 1]);
        $assessment = Assessment::query()->create(['owner_id' => $tutor->id, 'title' => 'Quadratics check', 'kind' => 'tutor_assessment', 'pass_percent' => 50]);
        $assessment->questions()->attach($question->id, ['position' => 1]);
        $outsider = $this->adult('Not in the lesson');

        $this->actingAs($tutor)->get(route('classroom.after', $session))->assertOk()->assertSee('Quadratics check');
        $this->actingAs($tutor)->post(route('classroom.follow-up', $session), ['assessment_id' => $assessment->id, 'learner_id' => $outsider->id])->assertSessionHasErrors('learner_id');
        $this->actingAs($tutor)->post(route('classroom.follow-up', $session), ['assessment_id' => $assessment->id, 'learner_id' => $b->id, 'due_at' => now()->addDays(3)->toDateTimeString()])
            ->assertRedirect()->assertSessionHas('success');

        $assignment = Assignment::query()->sole();
        $this->assertSame($b->id, $assignment->learner_id);
        $this->assertSame($tutor->id, $assignment->assigned_by);
        Event::assertDispatched(FollowUpAssigned::class);
    }

    public function test_the_room_emits_events_for_comms_and_the_sweep_announces_lessons_once(): void
    {
        Event::fake([ClassroomOpened::class, ClassroomEnded::class, LessonStartingSoon::class]);
        [$tutor, $profile] = $this->tutor();
        $learner = $this->adult('Learner');
        $soon = $this->lesson($profile, $learner, minutes: 12);
        $this->lesson($profile, $this->adult('Later'), minutes: 120);

        $this->artisan('classroom:sweep')->assertSuccessful();
        $this->artisan('classroom:sweep')->assertSuccessful();
        Event::assertDispatchedTimes(LessonStartingSoon::class, 1);

        $this->travel(5)->minutes();
        $this->joinAs($tutor, $soon, 't')->assertOk();
        $this->joinAs($learner, $soon, 'l')->assertOk();
        Event::assertDispatchedTimes(ClassroomOpened::class, 1);

        // The room closes by itself after the grace time.
        $this->travel(7 + 60 + 31)->minutes();
        $this->artisan('classroom:sweep')->assertSuccessful();
        Event::assertDispatched(ClassroomEnded::class, fn ($e) => $e->context['reason'] === 'closed');
        $this->assertTrue(app(ClassroomService::class)->for($soon)->isEnded());
    }

    public function test_the_pages_render_for_each_role_and_the_index_lists_my_lessons(): void
    {
        [$tutor, $profile] = $this->tutor();
        $child = $this->child('Lerato');
        $guardian = $this->adult('Mother');
        $this->linkGuardian($child, $guardian);
        $session = $this->lesson($profile, $child, minutes: 5);

        $this->actingAs($tutor)->get(route('classroom.show', $session))->assertOk()->assertSee('Your meeting link');
        $this->actingAs($child)->get(route('classroom.show', $session))->assertOk()->assertSee('Ask my parent or guardian to approve');
        $this->actingAs($guardian)->get(route('classroom.show', $session))->assertOk();
        $this->actingAs($tutor)->get(route('classroom.after', $session))->assertOk()->assertSee('Attendance');
        $this->actingAs($tutor)->get(route('classroom.index'))->assertOk()->assertSee('Lerato')->assertSee('Open the classroom');
        $this->actingAs($child)->get(route('classroom.index'))->assertOk()->assertSee('Mathematics');
    }

    public function test_the_fallback_link_must_be_https_and_is_the_tutors_to_set(): void
    {
        [$tutor, $profile] = $this->tutor();
        $learner = $this->adult('Learner');
        $session = $this->lesson($profile, $learner);

        $this->actingAs($tutor)->post(route('classroom.link', $session), ['external_url' => 'http://meet.example.com/abc'])->assertSessionHasErrors('external_url');
        $this->actingAs($tutor)->post(route('classroom.link', $session), ['external_url' => 'javascript:alert(1)'])->assertSessionHasErrors('external_url');
        $this->actingAs($learner)->post(route('classroom.link', $session), ['external_url' => 'https://meet.example.com/abc'])->assertForbidden();
        $this->actingAs($tutor)->post(route('classroom.link', $session), ['external_url' => 'https://meet.example.com/abc-defg'])->assertSessionHasNoErrors();

        $this->actingAs($learner)->get(route('classroom.show', $session))->assertSee('https://meet.example.com/abc-defg');
    }
}
