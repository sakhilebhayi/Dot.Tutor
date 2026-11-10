<?php

namespace Tests\Feature\Safety;

use App\Domain\Safety\Enums\IntegrityKind;
use App\Domain\Safety\Models\AccountRestriction;
use App\Domain\Safety\Models\ScreeningCheck;
use App\Domain\Safety\Services\IntegrityCases;
use App\Domain\Safety\Services\Screening;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ScreeningAndIntegrityTest extends TestCase
{
    use BuildsSafetyState;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('safety');
    }

    private function record($operator, $tutor, string $kind, bool $evidence = true)
    {
        return $this->actingAs($operator)->post(route('safety.operator.screening.store', $tutor), array_filter([
            'kind' => $kind,
            'checked_on' => now()->subDay()->toDateString(),
            'expires_on' => now()->addYear()->toDateString(),
            'reference' => 'DSD Form 29 reply 2026-11',
            'evidence' => $evidence ? UploadedFile::fake()->create('reply.pdf', 50, 'application/pdf') : null,
        ]), ['Accept' => 'application/json']);
    }

    public function test_a_recorded_check_counts_only_after_a_second_operator_clears_it(): void
    {
        $tutor = $this->adult('Tutor');
        $recorder = $this->operator('Recorder');
        $reviewer = $this->operator('Reviewer');
        $screening = app(Screening::class);

        $ids = [];
        foreach (config('safety.screening.required_kinds') as $kind) {
            $ids[] = $this->record($recorder, $tutor, $kind)->assertCreated()->assertJsonPath('check.status', 'pending_review')->json('check.id');
        }

        $this->assertFalse($screening->isClearedForMinors($tutor));

        $this->actingAs($recorder)->postJson(route('safety.operator.screening.review', $ids[0]), ['decision' => 'cleared'])
            ->assertUnprocessable();

        foreach ($ids as $id) {
            $this->actingAs($reviewer)->postJson(route('safety.operator.screening.review', $id), ['decision' => 'cleared'])->assertOk();
        }

        $this->assertTrue($screening->isClearedForMinors($tutor));
        $this->actingAs($reviewer)->getJson(route('safety.operator.screening.user', $tutor))->assertJsonPath('cleared_for_minors', true);
        $this->assertDatabaseHas('safety_audit_log', ['action' => 'safety.screening.cleared', 'actor_id' => $reviewer->id]);
    }

    public function test_a_check_without_evidence_cannot_be_cleared(): void
    {
        $tutor = $this->adult('Tutor');
        $id = $this->record($this->operator('A'), $tutor, 'police_clearance', evidence: false)->assertCreated()->json('check.id');

        $this->actingAs($this->operator('B'))->postJson(route('safety.operator.screening.review', $id), ['decision' => 'cleared'])->assertUnprocessable();
    }

    public function test_an_expired_or_revoked_check_stops_counting(): void
    {
        $tutor = $this->adult('Tutor');
        $this->screen($tutor);
        $screening = app(Screening::class);
        $this->assertTrue($screening->isClearedForMinors($tutor));

        ScreeningCheck::query()->where('user_id', $tutor->id)->update(['expires_on' => now()->subDay()->toDateString()]);
        $this->assertFalse($screening->isClearedForMinors($tutor));
        $this->assertSame(config('safety.screening.required_kinds'), $screening->missingKinds($tutor));

        $other = $this->adult('Other');
        $this->screen($other);
        $check = ScreeningCheck::query()->where('user_id', $other->id)->first();
        $this->actingAs($this->operator())->postJson(route('safety.operator.screening.revoke', $check), ['reason' => 'New information'])->assertOk();
        $this->assertFalse($screening->isClearedForMinors($other));
    }

    public function test_evidence_is_private_to_operators(): void
    {
        $tutor = $this->adult('Tutor');
        $id = $this->record($this->operator('A'), $tutor, 'police_clearance')->json('check.id');

        $this->actingAs($tutor)->get(route('safety.operator.screening.evidence', $id))->assertForbidden();
        $this->actingAs($this->operator('B'))->get(route('safety.operator.screening.evidence', $id))->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertDatabaseHas('safety_audit_log', ['action' => 'safety.screening.evidence_viewed']);
    }

    public function test_an_upheld_integrity_case_with_a_suspension_suspends_the_person(): void
    {
        $operator = $this->operator();
        $tutor = $this->adult('Tutor');

        $id = $this->actingAs($operator)->postJson(route('safety.operator.integrity.store'), [
            'kind' => 'misleading_qualification', 'summary' => 'Claims a PhD that the university cannot confirm.', 'subject_user_id' => $tutor->id,
        ])->assertCreated()->json('case.id');

        $this->actingAs($operator)->postJson(route('safety.operator.integrity.decide', $id), ['upheld' => false, 'finding' => 'x', 'sanction' => 'suspension'])
            ->assertUnprocessable();

        $this->actingAs($operator)->postJson(route('safety.operator.integrity.decide', $id), [
            'upheld' => true, 'finding' => 'No degree on record.', 'sanction' => 'suspension', 'days' => 30,
        ])->assertOk()->assertJsonPath('case.status', 'upheld');

        $this->assertSame(AccountRestriction::SUSPENSION, AccountRestriction::query()->where('user_id', $tutor->id)->sole()->kind);
        $this->actingAs($operator)->postJson(route('safety.operator.integrity.decide', $id), ['upheld' => true, 'finding' => 'x', 'sanction' => 'none'])->assertUnprocessable();
    }

    public function test_every_integrity_policy_area_can_be_opened(): void
    {
        $operator = $this->operator();

        foreach (IntegrityKind::cases() as $kind) {
            app(IntegrityCases::class)->open($kind, 'Test of '.$kind->label(), $operator);
        }

        $this->actingAs($operator)->getJson(route('safety.operator.integrity.index'))->assertOk()->assertJsonCount(count(IntegrityKind::cases()), 'cases');
    }
}
