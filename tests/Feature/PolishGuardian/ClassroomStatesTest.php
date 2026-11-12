<?php

namespace Tests\Feature\PolishGuardian;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Classroom\BuildsClassroom;
use Tests\TestCase;

/** The classroom's states and wording after the polish wave (critic B, classroom/55 and 56; D-2106 to D-2108). */
class ClassroomStatesTest extends TestCase
{
    use BuildsClassroom;
    use RefreshDatabase;

    public function test_a_learner_before_joining_sees_plain_words_and_join_under_the_preview(): void
    {
        [, $profile] = $this->tutor();
        $learner = $this->adult('Learner');
        $session = $this->lesson($profile, $learner);

        $html = $this->actingAs($learner)->get(route('classroom.show', $session))->assertOk()
            ->assertSee('Not joined')
            ->assertSee('Some school and office networks block video calls')
            ->assertSee('Your tutor has not added a meeting link yet.')
            ->assertDontSee('relay')->assertDontSee('TURN')
            ->getContent();

        // Join comes straight after the preview, before the device selects (the phone order).
        $this->assertLessThan(strpos($html, 'id="cr-sel-cam"'), strpos($html, 'id="cr-join"'));
        $this->assertLessThan(strpos($html, 'id="cr-join"'), strpos($html, 'id="cr-lobby-video"'));
    }

    public function test_an_ended_lesson_says_ended_and_drops_the_connection_help(): void
    {
        [$tutor, $profile] = $this->tutor();
        $learner = $this->adult('Learner');
        $session = $this->lesson($profile, $learner);
        $this->joinAs($tutor, $session, 't');
        $this->actingAs($tutor)->postJson(route('classroom.api.end', $session))->assertOk();

        $this->actingAs($learner)->get(route('classroom.show', $session))->assertOk()
            ->assertSee('The tutor ended this lesson.')
            ->assertSee('data-state="ended">Ended</span>', false)
            ->assertDontSee('Not joined')
            ->assertDontSee('If the classroom cannot connect')
            ->assertDontSee('relay')
            ->assertSee('Open the lesson record');

        // The tutor may reopen it, and is told so instead of being invited as if nothing happened.
        $this->actingAs($tutor)->get(route('classroom.show', $session))->assertOk()
            ->assertSee('You ended this lesson')
            ->assertSee('Reopen the lesson')
            ->assertSee('data-state="ended">Ended</span>', false)
            ->assertDontSee('Not joined');
    }

    public function test_the_tutor_still_gets_the_technical_reason_and_the_link_form(): void
    {
        [$tutor, $profile] = $this->tutor();
        $session = $this->lesson($profile, $this->adult('Learner'));

        $this->actingAs($tutor)->get(route('classroom.show', $session))->assertOk()
            ->assertSee('No relay server is set up')
            ->assertSee('Your meeting link')
            ->assertSee('Join the lesson');
    }
}
