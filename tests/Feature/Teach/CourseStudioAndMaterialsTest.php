<?php

namespace Tests\Feature\Teach;

use App\Domain\Learning\Models\Assessment;
use App\Domain\Learning\Models\Assignment;
use App\Domain\Learning\Models\Course;
use App\Domain\Learning\Models\LibraryResource;
use App\Domain\Learning\Models\Question;
use App\Domain\Learning\Models\QuestionBank;
use App\Models\TutorSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\Feature\Acceptance\BuildsJourneys;
use Tests\TestCase;

/**
 * The courses studio and materials screens drive the learning domain: a first-time creator builds, prices and
 * publishes a course, writes a question bank and assigns an assessment to their own student, and uploads to the
 * library. The learning rules (publish checks, answer keys, teaching relationship) still decide.
 */
class CourseStudioAndMaterialsTest extends TestCase
{
    use BuildsJourneys;
    use RefreshDatabase;

    private function pdf(): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('notes.pdf', "%PDF-1.4\n%\xe2\xe3\xcf\xd3\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF\n");
    }

    public function test_a_first_time_creator_builds_prices_and_publishes_a_course(): void
    {
        $this->setUpWorld();
        [$tutor] = $this->tutorReadyToTeach('Lerato Khumalo', 30000);
        $grade11 = $this->level('za-caps', 'grade-11');

        $this->actingAs($tutor)->post(route('teach.courses.store'), [
            'title' => 'Grade 11 functions', 'subject_id' => $this->subject('mathematics')->id, 'level_id' => $grade11->id, 'original_locale' => 'en',
        ])->assertSessionHasNoErrors();
        $course = Course::query()->where('owner_id', $tutor->id)->sole();
        $this->assertSame($grade11->curriculum_id, (int) $course->curriculum_id, 'The curriculum comes with the level');
        $this->assertSame('draft', $course->status->value);

        // The publish tab names what is missing in plain words.
        $this->actingAs($tutor)->get(route('teach.courses.show', [$course, 'tab' => 'publish']))->assertOk()
            ->assertSee('Add at least one learning objective.')->assertSee('Choose whether the course is free or paid.');

        $this->actingAs($tutor)->put(route('teach.courses.details', $course), [
            'tab' => 'basics', 'next' => 'outcomes', 'title' => 'Grade 11 functions', 'level_id' => $grade11->id, 'subject_id' => $this->subject('mathematics')->id,
            'description' => 'Parabolas, hyperbolas and exponential graphs, step by step with practice.', 'original_locale' => 'en',
        ])->assertRedirect(route('teach.courses.show', [$course, 'tab' => 'outcomes']));
        $this->actingAs($tutor)->put(route('teach.courses.details', $course), ['tab' => 'outcomes', 'objectives' => ['Sketch a parabola from its equation', ''], 'workload_minutes' => '240'])
            ->assertSessionHasNoErrors();
        $this->actingAs($tutor)->put(route('teach.courses.details', $course), ['tab' => 'price', 'pricing' => 'paid', 'price' => '349.99', 'refund_policy' => 'Full refund within 7 days if under a quarter is done.'])
            ->assertSessionHasNoErrors();
        $this->assertSame(34999, $course->fresh()->price_minor);

        // Structure goes straight to the learning studio routes, which answer back to this page.
        $page = route('teach.courses.show', [$course, 'tab' => 'structure']);
        $this->actingAs($tutor)->from($page)->post(route('learning.studio.sections.store', $course), ['title' => 'Parabolas'])->assertRedirect($page);
        $section = $course->sections()->sole();
        $this->actingAs($tutor)->from($page)->post(route('learning.studio.lessons.store', $section), ['title' => 'Notes', 'type' => 'document', 'file' => $this->pdf()])->assertRedirect($page);
        $this->actingAs($tutor)->get($page)->assertOk()->assertSee('notes.pdf')->assertSee('Preview as a learner');

        // Quiz for the course, made from the quizzes tab.
        $this->actingAs($tutor)->post(route('teach.courses.quizzes.store', $course), ['title' => 'Check yourself', 'pass_percent' => 60])->assertSessionHasNoErrors();
        $this->assertSame('course_quiz', Assessment::query()->where('course_id', $course->id)->sole()->kind->value);

        $this->actingAs($tutor)->get(route('teach.courses.show', [$course, 'tab' => 'publish']))->assertOk()->assertSee('Ready to publish');
        $this->actingAs($tutor)->from(route('teach.courses.show', [$course, 'tab' => 'publish']))->post(route('learning.studio.courses.publish', $course))->assertSessionHasNoErrors();
        $this->assertSame('published', $course->fresh()->status->value);
        $this->actingAs($tutor)->get(route('teach.courses'))->assertOk()->assertSee('Published');
    }

    public function test_question_banks_assessments_and_assigning_only_to_own_students(): void
    {
        $this->setUpWorld();
        [$tutor, $profile, $offeringId] = $this->tutorReadyToTeach('Lerato Khumalo', 30000);
        $student = $this->person('Anele Ngcobo');
        $this->learnerProfile($student, 20);
        $bookingId = $this->requestLesson($student, $offeringId, $profile)->json('data.id');
        TutorSession::query()->whereKey($bookingId)->update(['status' => 'confirmed']);
        $stranger = $this->person('Not A Student');

        $this->actingAs($tutor)->post(route('teach.materials.banks.store'), ['title' => 'Functions', 'topic' => 'Functions'])->assertSessionHasNoErrors();
        $bank = QuestionBank::query()->sole();
        $this->actingAs($tutor)->post(route('teach.materials.questions.store', $bank->id), [
            'type' => 'single_choice', 'prompt' => 'Which graph is a parabola?', 'option_A' => 'y = x^2', 'option_B' => 'y = 1/x', 'correct' => 'A', 'marks' => 1,
        ])->assertSessionHasNoErrors();
        $this->actingAs($tutor)->post(route('teach.materials.questions.store', $bank->id), [
            'type' => 'numeric', 'prompt' => 'What is 2 squared?', 'numeric_value' => '4', 'marks' => 1,
        ])->assertSessionHasNoErrors();
        $this->actingAs($tutor)->post(route('teach.materials.questions.store', $bank->id), [
            'type' => 'single_choice', 'prompt' => 'Broken', 'option_A' => 'only one', 'correct' => 'A',
        ])->assertSessionHasErrors('answer');
        $this->assertSame(2, Question::query()->count());
        $this->assertSame(['key' => 'A'], Question::query()->orderBy('id')->first()->answer);

        $this->actingAs($tutor)->post(route('teach.materials.assessments.store'), ['title' => 'Functions check', 'kind' => 'tutor_assessment', 'pass_percent' => 50])->assertSessionHasNoErrors();
        $assessment = Assessment::query()->sole();
        $this->actingAs($tutor)->post(route('teach.materials.assessments.attach', $assessment->id), ['question_ids' => Question::query()->pluck('id')->all()])->assertSessionHasNoErrors();
        $this->assertSame(2, $assessment->questions()->count());

        $this->actingAs($tutor)->get(route('teach.materials.assessments.show', $assessment->id))->assertOk()->assertSee('Anele Ngcobo')->assertDontSee('Not A Student');
        $this->actingAs($tutor)->post(route('teach.materials.assessments.assign', $assessment->id), ['learner_id' => $student->id])->assertSessionHas('success');
        $this->actingAs($tutor)->post(route('teach.materials.assessments.assign', $assessment->id), ['learner_id' => $stranger->id])->assertSessionHas('error');
        $this->assertSame([$student->id], Assignment::query()->pluck('learner_id')->all());

        // Another tutor cannot open the bank or the assessment.
        [$other] = $this->tutorReadyToTeach('Other Tutor', 20000);
        $this->actingAs($other)->get(route('teach.materials.banks.show', $bank->id))->assertNotFound();
        $this->actingAs($other)->get(route('teach.materials.assessments.show', $assessment->id))->assertForbidden();
        $this->actingAs($other)->post(route('teach.materials.questions.store', $bank->id), ['type' => 'numeric', 'prompt' => 'x', 'numeric_value' => '1'])->assertNotFound();

        // The student page shows the assignment the tutor set.
        $this->actingAs($tutor)->get(route('teach.students.show', $student->id))->assertOk()->assertSee('Functions check')->assertSee('Not attempted');
    }

    public function test_library_uploads_take_a_licence_and_a_price_in_rands(): void
    {
        $this->setUpWorld();
        [$tutor] = $this->tutorReadyToTeach('Lerato Khumalo', 30000);

        $this->actingAs($tutor)->post(route('teach.materials.store'), [
            'title' => 'Functions summary', 'type' => 'summary', 'language' => 'en', 'ownership' => 'own_work', 'licence' => 'all_rights_reserved',
            'price' => '45', 'file' => $this->pdf(),
        ])->assertSessionHasErrors('rights_confirmed');

        $this->actingAs($tutor)->post(route('teach.materials.store'), [
            'title' => 'Functions summary', 'type' => 'summary', 'language' => 'en', 'ownership' => 'own_work', 'licence' => 'all_rights_reserved',
            'price' => '45', 'level_id' => $this->level('za-caps', 'grade-11')->id, 'subject_id' => $this->subject('mathematics')->id,
            'rights_confirmed' => '1', 'file' => $this->pdf(),
        ])->assertRedirect(route('teach.materials'));

        $resource = LibraryResource::query()->sole();
        $this->assertSame(4500, $resource->price_minor);
        $this->assertFalse((bool) $resource->is_free);
        $this->actingAs($tutor)->get(route('teach.materials'))->assertOk()->assertSee('Functions summary')->assertSee('All rights reserved');
    }

    public function test_group_lessons_are_scheduled_once_from_a_group_offering(): void
    {
        $this->setUpWorld();
        [$tutor, $profile] = $this->tutorReadyToTeach('Lerato Khumalo', 30000);
        $maths = $this->subject('mathematics');
        $this->actingAs($tutor)->post(route('teach.offerings.store'), [
            'type' => 'group', 'title' => 'Saturday revision group', 'price' => '120', 'duration_minutes' => 90, 'format' => 'online',
            'subject_ids' => [$maths->id], 'capacity' => 6,
        ])->assertSessionHasNoErrors();
        $offering = $profile->offerings()->where('type', 'group')->sole();

        $date = now('Africa/Johannesburg')->addDays(5)->format('Y-m-d');
        $payload = ['subject_id' => $maths->id, 'date' => $date, 'time' => '10:00', 'idempotency_key' => 'g-1'];
        $this->actingAs($tutor)->post(route('teach.bookings.group', $offering), $payload)->assertRedirect(route('teach.bookings', ['view' => 'groups']));
        $this->actingAs($tutor)->post(route('teach.bookings.group', $offering), $payload);
        $this->assertSame(1, TutorSession::query()->where('kind', 'group')->count(), 'The same form sent twice schedules once');
        $this->actingAs($tutor)->get(route('teach.bookings', ['view' => 'groups']))->assertOk()->assertSee('0 of 6 seats taken');
        $this->actingAs($tutor)->get(route('teach.calendar', ['week' => $date]))->assertOk()->assertSee('Group: 0 of 6');
    }
}
