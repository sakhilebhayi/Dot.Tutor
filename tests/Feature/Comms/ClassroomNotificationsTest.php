<?php

namespace Tests\Feature\Comms;

use App\Domain\Classroom\Events\ClassroomOpened;
use App\Domain\Classroom\Events\LessonStartingSoon;
use App\Domain\Classroom\Services\ClassroomService;
use App\Domain\Comms\Contracts\MinorRouting;
use App\Domain\Comms\Models\NotificationDelivery;
use App\Domain\Comms\Reminders;
use App\Domain\Learning\Models\Assessment;
use App\Domain\Learning\Models\Question;
use App\Domain\Learning\Models\QuestionBank;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\ChannelManager;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Notification;
use Tests\Feature\Classroom\BuildsClassroom;
use Tests\TestCase;

/**
 * Comms listens to the classroom (D-812), driven through the real sweep, room API and after-lesson forms: starting
 * soon and the room opening reach the people in the lesson (not the one who walked in), the published summary
 * reaches learners and a minor's guardians, and ending the lesson or setting follow-up work adds no second notice.
 */
class ClassroomNotificationsTest extends TestCase
{
    use BuildsClassroom;
    use RefreshDatabase;

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

    public function test_starting_soon_reaches_the_tutor_and_learner_once_with_the_classroom_link_and_collapses_with_the_hour_reminder(): void
    {
        [$tutor, $profile] = $this->tutor('Tutor Thandi');
        $teen = $this->teen('Lerato Dlamini');
        $guardian = $this->adult('Mother Dlamini');
        $this->linkGuardian($teen, $guardian);
        $this->realNotifications();
        $session = $this->lesson($profile, $teen, minutes: 40);

        app(Reminders::class)->run();
        $this->assertCount(1, $this->inbox($teen, 'lesson_reminder'));

        $this->travel(28)->minutes();
        $this->artisan('classroom:sweep')->assertSuccessful();
        $this->artisan('classroom:sweep')->assertSuccessful();
        LessonStartingSoon::dispatch(app(ClassroomService::class)->for($session->fresh()), []); // a repeat dispatch adds nothing

        $tutorNotice = $this->inbox($tutor, 'lesson_starting')->sole();
        $this->assertSame('/lessons/'.$session->id.'/classroom', $tutorNotice->data['url']);
        $this->assertStringContainsString('with Lerato starts at', $tutorNotice->data['message']);
        $this->assertStringNotContainsString('Dlamini', $tutorNotice->data['message']);
        $this->assertTrue($tutorNotice->data['about_minor']);
        $this->assertSame('reminders', $tutorNotice->data['category']);

        $learnerNotice = $this->inbox($teen, 'lesson_starting')->sole();
        $this->assertSame('Mathematics lesson with Tutor Thandi starts soon', $learnerNotice->data['title']);
        // "at HH:MM" today, "on Ddd j M at HH:MM" when the opening falls on another day (D-2312).
        $this->assertMatchesRegularExpression('/The classroom opens (at \d\d:\d\d|on \w{3} \d{1,2} \w{3} at \d\d:\d\d)/', $learnerNotice->data['message']);
        $this->assertSame('booking-'.$session->id, $learnerNotice->data['collapse_key']);
        $this->assertSame($this->inbox($teen, 'lesson_reminder')->sole()->data['collapse_key'], $learnerNotice->data['collapse_key']);
        $this->assertCount(0, $this->inbox($guardian, 'lesson_starting')); // like the hour reminder: the learner's
        $this->assertNotNull(NotificationDelivery::query()->where('user_id', $tutor->id)->where('type', 'lesson_starting')->where('channel', 'email')->first());
    }

    public function test_the_room_opening_tells_the_others_and_never_the_person_who_walked_in(): void
    {
        [$tutor, $profile] = $this->tutor('Tutor Thandi');
        $learner = $this->adult('Sipho Ndlovu');
        $this->realNotifications();
        $session = $this->lesson($profile, $learner, minutes: 5);

        $this->joinAs($tutor, $session, 't')->assertOk();
        $this->joinAs($learner, $session, 'l')->assertOk();
        ClassroomOpened::dispatch(app(ClassroomService::class)->for($session->fresh()), ['by' => $tutor->id]);

        $notice = $this->inbox($learner, 'classroom_open')->sole();
        $this->assertSame('Tutor Thandi is in the classroom', $notice->data['title']);
        $this->assertSame('/lessons/'.$session->id.'/classroom', $notice->data['url']);
        $this->assertCount(0, $this->inbox($tutor, 'classroom_open'));

        // Ending the lesson sends nothing of its own (the completion notice and the summary do that).
        $before = DatabaseNotification::query()->count();
        $this->actingAs($tutor)->postJson(route('classroom.api.end', $session))->assertOk();
        $this->assertSame($before, DatabaseNotification::query()->count());
    }

    public function test_in_a_group_lesson_a_learner_opening_the_room_tells_the_tutor_and_the_other_learners(): void
    {
        [$tutor, $profile] = $this->tutor('Tutor Thandi');
        $a = $this->adult('Anna Botha');
        $b = $this->adult('Ben Smit');
        $this->realNotifications();
        $session = $this->groupLesson($profile, [$a, $b], minutes: 5);

        $this->joinAs($a, $session, 'a')->assertOk();

        $this->assertSame('Anna Botha is waiting in the classroom', $this->inbox($tutor, 'classroom_open')->sole()->data['title']);
        $this->assertSame('The classroom is open', $this->inbox($b, 'classroom_open')->sole()->data['title']);
        $this->assertCount(0, $this->inbox($a, 'classroom_open'));
    }

    public function test_a_published_summary_reaches_the_learner_and_guardian_once_and_not_the_tutor(): void
    {
        [$tutor, $profile] = $this->tutor('Tutor Thandi');
        $teen = $this->teen('Lerato Dlamini');
        $guardian = $this->adult('Mother Dlamini');
        $this->linkGuardian($teen, $guardian);
        $this->realNotifications();
        $session = $this->lesson($profile, $teen, minutes: -30);

        $this->actingAs($tutor)->post(route('classroom.summary', $session), ['summary' => 'We factorised quadratics.', 'publish' => 0])->assertRedirect();
        $this->assertCount(0, $this->inbox($teen, 'lesson_summary'));

        $this->actingAs($tutor)->post(route('classroom.summary', $session), ['summary' => 'We factorised quadratics.', 'publish' => 1])->assertRedirect();
        $this->actingAs($tutor)->post(route('classroom.summary', $session), ['summary' => 'We factorised quadratics. Practise 4 to 9.', 'publish' => 1])->assertRedirect();

        $after = '/lessons/'.$session->id.'/classroom/after';
        $learnerNotice = $this->inbox($teen, 'lesson_summary')->sole();
        $this->assertSame('Summary of your Mathematics lesson', $learnerNotice->data['title']);
        $this->assertSame($after, $learnerNotice->data['url']);
        $guardianNotice = $this->inbox($guardian, 'lesson_summary')->sole();
        $this->assertSame('Lerato\'s lesson summary is ready', $guardianNotice->data['title']);
        $this->assertSame($after, $guardianNotice->data['url']);
        $this->assertCount(0, $this->inbox($tutor, 'lesson_summary'));
        $this->assertSame('lessons', $learnerNotice->data['category']);
        $this->actingAs($guardian)->get($after)->assertOk(); // the guardian's link opens
    }

    public function test_follow_up_work_arrives_as_one_assignment_notice(): void
    {
        [$tutor, $profile] = $this->tutor('Tutor Thandi');
        $learner = $this->adult('Sipho Ndlovu');
        $this->realNotifications();
        $session = $this->lesson($profile, $learner, minutes: -30);
        $bank = QuestionBank::query()->create(['owner_id' => $tutor->id, 'title' => 'Quadratics', 'topic' => 'Quadratics']);
        $question = Question::query()->create(['bank_id' => $bank->id, 'type' => 'numeric', 'prompt' => 'Solve x^2 = 9, x > 0', 'answer' => ['value' => 3, 'tolerance' => 0], 'marks' => 1]);
        $assessment = Assessment::query()->create(['owner_id' => $tutor->id, 'title' => 'Quadratics check', 'kind' => 'tutor_assessment', 'pass_percent' => 50]);
        $assessment->questions()->attach($question->id, ['position' => 1]);
        $due = now()->addDays(3)->toDateTimeString();

        $this->actingAs($tutor)->post(route('classroom.follow-up', $session), ['assessment_id' => $assessment->id, 'learner_id' => $learner->id, 'due_at' => $due])->assertSessionHas('success');
        $this->actingAs($tutor)->post(route('classroom.follow-up', $session), ['assessment_id' => $assessment->id, 'learner_id' => $learner->id, 'due_at' => $due])->assertSessionHas('success');

        $this->assertSame(['assignment_set'], $this->inbox($learner)->map(fn ($n) => $n->data['type'])->all());
        $this->assertCount(0, $this->inbox($tutor));
    }
}
