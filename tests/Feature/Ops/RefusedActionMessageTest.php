<?php

namespace Tests\Feature\Ops;

use App\Domain\Ops\Access\OpsRole;
use App\Domain\Ops\Access\RoleAdministration;
use App\Domain\Safety\Models\ScreeningCheck;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * QA regression: a rule a service refuses with a validation message that has no field of its own (here "a check
 * cannot be cleared without evidence attached") used to redirect back silently; the reviewer saw nothing happen.
 */
class RefusedActionMessageTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_refused_screening_review_tells_the_reviewer_why(): void
    {
        $recorder = User::factory()->create();
        $reviewer = User::factory()->create();
        foreach ([$recorder, $reviewer] as $officer) {
            app(RoleAdministration::class)->setRoles(null, $officer, [OpsRole::SafeguardingOfficer->value]);
        }
        $tutor = User::factory()->create();
        $check = ScreeningCheck::query()->forceCreate([
            'user_id' => $tutor->id, 'kind' => 'child_protection_register', 'status' => ScreeningCheck::PENDING_REVIEW,
            'checked_on' => now()->toDateString(), 'recorded_by_id' => $recorder->id,
        ]);

        $this->actingAs($reviewer)->from(route('ops.safeguarding.screening'))
            ->post(route('ops.safeguarding.screening.review', $check), ['decision' => 'cleared'])
            ->assertRedirect(route('ops.safeguarding.screening'));

        $this->actingAs($reviewer)->get(route('ops.safeguarding.screening'))->assertOk()
            ->assertSee('Not saved: A check cannot be cleared without evidence attached.');
        $this->assertSame(ScreeningCheck::PENDING_REVIEW, $check->fresh()->status);
    }
}
