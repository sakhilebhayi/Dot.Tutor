<?php

namespace Tests\Feature;

use App\Domain\Safety\Models\GuardianLink;
use App\Models\TutorProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/** D-1801: after sign-in each person lands on their role's home, a deep link still wins. */
class RoleHomeAfterSignInTest extends TestCase
{
    use RefreshDatabase;

    private function signIn(User $user): TestResponse
    {
        return $this->post('/login', ['email' => $user->email, 'password' => 'password']);
    }

    public function test_a_learner_lands_on_learn(): void
    {
        $this->signIn(User::factory()->create())->assertRedirect('/learn');
    }

    public function test_a_tutor_lands_on_the_workspace_even_with_a_draft_profile(): void
    {
        $user = User::factory()->create();
        TutorProfile::create(['user_id' => $user->id, 'hourly_rate' => 250, 'status' => 'draft']);

        $this->signIn($user)->assertRedirect('/teach');
    }

    public function test_an_operator_lands_on_ops_before_any_other_role(): void
    {
        $user = User::factory()->create();
        $user->forceFill(['is_platform_operator' => true])->save();
        TutorProfile::create(['user_id' => $user->id, 'hourly_rate' => 250, 'status' => 'approved']);

        $this->signIn($user)->assertRedirect('/ops');
    }

    public function test_a_guardian_without_a_learning_profile_lands_on_guardian(): void
    {
        $guardian = User::factory()->create();
        $child = User::factory()->create();
        GuardianLink::query()->create(['learner_id' => $child->id, 'guardian_id' => $guardian->id, 'relationship' => 'parent', 'status' => GuardianLink::ACTIVE]);

        $this->signIn($guardian)->assertRedirect('/guardian');
    }

    public function test_a_page_asked_for_before_sign_in_wins(): void
    {
        $user = User::factory()->create();
        $this->get('/learn/progress')->assertRedirect('/login');

        $this->signIn($user)->assertRedirect('/learn/progress');
    }
}
