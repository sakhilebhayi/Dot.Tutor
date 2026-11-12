<?php

namespace Tests\Feature\Ops;

use App\Domain\Safety\Contracts\GuardianApprovals;
use App\Domain\Safety\Models\GuardianApproval;
use App\Domain\Safety\Services\Guardians;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\Feature\Safety\BuildsSafetyState;
use Tests\TestCase;

/** The guardian experience: phone-first pages on the safety domain's data and endpoints (brief sections 15 and 25). */
class GuardianViewTest extends TestCase
{
    use BuildsSafetyState;
    use RefreshDatabase;

    public function test_a_guardian_sees_what_waits_and_decides_it_through_the_safety_domain(): void
    {
        Notification::fake();
        $child = $this->child('Sipho Child');
        $guardian = $this->adult('Thandi Guardian');
        $this->linkGuardian($child, $guardian, false, 9);

        // The item itself does not matter to this screen; the approval row is what the guardian decides.
        $approval = app(GuardianApprovals::class)->request($child, 'purchase', $child, ['summary' => 'Grade 4 fractions course', 'amount_minor' => 25000, 'currency' => 'ZAR']);

        $this->actingAs($guardian)->get(route('guardian.home'))->assertOk()
            ->assertSee('Your children on Dot.Tutor')->assertSee('Grade 4 fractions course')->assertSee('250.00')->assertSee('Sipho Child');

        $this->actingAs($guardian)->get(route('guardian.child', $child->id))->assertOk()
            ->assertSee('every booking, purchase, paid room and live one-to-one lesson needs your approval')
            ->assertSee('Lesson recordings');

        $this->actingAs($guardian)->from(route('guardian.home'))
            ->post(route('safety.guardian.approvals.decide', $approval->id), ['decision' => 'decline', 'note' => 'Not this month'])
            ->assertRedirect(route('guardian.home'));
        $this->assertSame(GuardianApproval::DECLINED, $approval->fresh()->status);

        $this->actingAs($guardian)->get(route('guardian.home'))->assertSee('Nothing is waiting for your approval');
    }

    public function test_a_teens_guardian_chooses_what_needs_approval(): void
    {
        Notification::fake();
        $teen = $this->teen('Ayanda Teen');
        $guardian = $this->adult('Parent');
        $link = $this->linkGuardian($teen, $guardian, false, 15);

        $this->actingAs($guardian)->get(route('guardian.child', $teen->id))->assertOk()->assertSee('What needs your approval');

        $this->actingAs($guardian)->post(route('safety.guardian.links.settings', $link), ['settings' => ['booking' => '0', 'purchase' => '1', 'paid_room' => '1', 'live_lesson' => '1']])->assertRedirect();
        $this->assertFalse(app(GuardianApprovals::class)->requires($teen, 'booking'));
        $this->assertTrue(app(GuardianApprovals::class)->requires($teen, 'live_lesson'));
    }

    public function test_a_guardian_cannot_open_someone_elses_child(): void
    {
        Notification::fake();
        $child = $this->child('Not Yours');
        $this->linkGuardian($child, $this->adult('Real Guardian'), false, 9);
        $stranger = $this->adult('Stranger');

        $this->actingAs($stranger)->get(route('guardian.child', $child->id))->assertNotFound();
        $this->actingAs($stranger)->get(route('guardian.home'))->assertOk()->assertSee('You are not linked to a learner')->assertDontSee('Not Yours');
    }

    public function test_a_minor_without_a_guardian_is_offered_the_invitation(): void
    {
        $child = $this->child('Lonely Child');

        $this->actingAs($child)->get(route('guardian.home'))->assertOk()->assertSee('Invite your parent or guardian');

        $adult = $this->adult('Adult Learner');
        $this->actingAs($adult)->get(route('guardian.home'))->assertOk()->assertDontSee('Invite your parent or guardian');
    }

    public function test_the_safety_guardian_page_and_the_acceptance_page_use_the_new_screens(): void
    {
        Notification::fake();
        $child = $this->child('Zola');
        $guardian = $this->adult('Guardian Zola');
        $this->linkGuardian($child, $guardian, false, 9);

        $this->actingAs($guardian)->get(route('safety.guardian.dashboard'))->assertOk()->assertSee('Your children on Dot.Tutor')->assertSee('td-app--learner', false);
        $this->actingAs($guardian)->getJson(route('safety.guardian.dashboard'))->assertOk()->assertJsonPath('children.0.name', 'Zola');

        $other = $this->child('Mpho');
        $invite = User::factory()->create(['email' => 'mpho.parent@example.com']);
        $result = app(Guardians::class)->invite($other, 'mpho.parent@example.com', 'parent');
        $this->actingAs($invite)->get($result['url'])->assertOk()
            ->assertSee('Be Mpho&#039;s guardian on Dot.Tutor', false)->assertSee('Consent text version')->assertSee('Give consent and link');
    }
}
