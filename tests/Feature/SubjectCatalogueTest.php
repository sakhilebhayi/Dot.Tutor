<?php

namespace Tests\Feature;

use App\Models\Subject;
use App\Models\TutorSession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsMarketplace;
use Tests\TestCase;

class SubjectCatalogueTest extends TestCase
{
    use BuildsMarketplace;
    use RefreshDatabase;

    public function test_only_operators_reach_the_catalogue(): void
    {
        $this->get('/operator/subjects')->assertRedirect('/login');
        $this->actingAs($this->makeUser())->get('/operator/subjects')->assertForbidden();
        $this->actingAs($this->makeUser())->post('/operator/subjects', ['name' => 'X', 'level' => 'high_school'])->assertForbidden();
        $this->assertSame(0, Subject::count());
    }

    public function test_an_operator_can_create_a_subject(): void
    {
        $this->actingAs($this->makeOperator())
            ->post('/operator/subjects', ['name' => 'Chemistry', 'level' => 'high_school'])
            ->assertRedirect(route('operator.subjects.index'));

        $subject = Subject::firstOrFail();
        $this->assertSame('Chemistry', $subject->name);
        $this->assertTrue($subject->is_active);
    }

    public function test_a_duplicate_name_at_the_same_level_is_refused(): void
    {
        $this->makeSubject('Chemistry');

        $this->actingAs($this->makeOperator())
            ->post('/operator/subjects', ['name' => 'chemistry', 'level' => 'high_school'])
            ->assertSessionHasErrors('name');
        $this->assertSame(1, Subject::count());
    }

    public function test_an_operator_can_rename_a_subject_and_history_follows_it(): void
    {
        $subject = $this->makeSubject('Maths');
        $session = $this->makeSession($this->makeTutor([], $subject), $this->makeUser(), $subject);

        $this->actingAs($this->makeOperator())
            ->put("/operator/subjects/{$subject->id}", ['name' => 'Mathematics', 'level' => 'high_school'])
            ->assertRedirect(route('operator.subjects.index'));

        $this->assertSame('Mathematics', $subject->fresh()->name);
        $this->assertSame('Mathematics', $session->fresh()->subject->name);
    }

    public function test_deactivated_subjects_are_hidden_from_new_bookings_but_kept_on_history(): void
    {
        $subject = $this->makeSubject('Latin');
        $profile = $this->makeTutor([], $subject);
        $this->openWeek($profile);
        $student = $this->makeUser();
        $past = $this->makeSession($profile, $student, $subject, ['status' => 'completed', 'starts_at' => now()->subDays(3)]);

        $this->actingAs($this->makeOperator())->post("/operator/subjects/{$subject->id}/deactivate")->assertRedirect();
        $this->assertFalse($subject->fresh()->is_active);

        // Hidden from the browse filter and the tutor page's booking form.
        $this->actingAs($student)->get('/tutors')->assertDontSee('Latin');
        $this->actingAs($student)->get("/tutors/{$profile->id}")->assertDontSee('<option value="'.$subject->id.'"', false);

        // Refused for a new booking.
        $this->actingAs($student)->post("/tutors/{$profile->id}/sessions", $this->bookingPayload($subject, $this->slotAt($profile, 2)))
            ->assertSessionHasErrors('subject_id');
        $this->assertSame(1, TutorSession::count());

        // Kept on history.
        $this->actingAs($student)->get(route('sessions.show', $past))->assertOk()->assertSee('Latin');

        // And can be switched back on.
        $this->actingAs($this->makeOperator())->post("/operator/subjects/{$subject->id}/activate");
        $this->assertTrue($subject->fresh()->is_active);
    }

    public function test_the_operator_flag_cannot_be_mass_assigned(): void
    {
        $user = User::create(['name' => 'Sneaky', 'email' => 'sneaky@example.test', 'password' => 'secret-pass-123', 'is_platform_operator' => true]);

        $this->assertFalse((bool) $user->fresh()->is_platform_operator);
    }
}
