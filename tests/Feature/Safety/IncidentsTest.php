<?php

namespace Tests\Feature\Safety;

use App\Domain\Safety\Models\Incident;
use App\Domain\Safety\Models\IncidentEvent;
use App\Domain\Safety\Notifications\IncidentAlert;
use App\Domain\Safety\Services\Incidents;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use LogicException;
use Tests\TestCase;

class IncidentsTest extends TestCase
{
    use BuildsSafetyState;
    use RefreshDatabase;

    public function test_an_unacknowledged_incident_escalates_when_its_timer_runs_out(): void
    {
        $operator = $this->operator();
        $incident = app(Incidents::class)->open('Grooming concern', 'critical', $operator);
        Notification::fake();

        $this->artisan('safety:escalate-incidents')->assertSuccessful();
        $this->assertSame(0, $incident->fresh()->escalation_level, 'not yet due');

        $this->travel(61)->minutes();
        $this->artisan('safety:escalate-incidents')->assertSuccessful();

        $this->assertSame(1, $incident->fresh()->escalation_level);
        Notification::assertSentTo($operator, IncidentAlert::class, fn (IncidentAlert $n) => $n->event === 'escalated');
        $this->assertDatabaseHas('safety_incident_events', ['incident_id' => $incident->id, 'type' => 'escalated']);

        foreach (range(1, 5) as $i) {
            $this->travel(61)->minutes();
            $this->artisan('safety:escalate-incidents');
        }
        $this->assertSame(config('safety.incidents.max_level'), $incident->fresh()->escalation_level);
    }

    public function test_acknowledging_stops_the_timer(): void
    {
        $operator = $this->operator();
        $incident = app(Incidents::class)->open('Concern', 'high', $operator);

        $this->actingAs($operator)->postJson(route('safety.operator.incidents.update', $incident), ['action' => 'acknowledge'])
            ->assertOk()->assertJsonPath('incident.status', 'acknowledged');

        $this->travel(5)->hours();
        $this->artisan('safety:escalate-incidents');
        $this->assertSame(0, $incident->fresh()->escalation_level);
        $this->assertSame($operator->id, $incident->fresh()->owner_id);
    }

    public function test_referral_and_closure_are_recorded_on_the_timeline(): void
    {
        $operator = $this->operator();

        $id = $this->actingAs($operator)->postJson(route('safety.operator.incidents.store'), ['title' => 'Disclosure in a lesson', 'severity' => 'critical'])
            ->assertCreated()->json('incident.id');

        $this->actingAs($operator)->postJson(route('safety.operator.incidents.update', $id), ['action' => 'note', 'body' => 'Spoke to the guardian.'])->assertOk();
        $this->actingAs($operator)->postJson(route('safety.operator.incidents.update', $id), ['action' => 'refer', 'body' => 'Reported to SAPS', 'external_reference' => 'CAS 123/11/2026'])
            ->assertOk()->assertJsonPath('incident.status', 'referred');
        $this->actingAs($operator)->postJson(route('safety.operator.incidents.update', $id), ['action' => 'close'])->assertUnprocessable();
        $this->actingAs($operator)->postJson(route('safety.operator.incidents.update', $id), ['action' => 'close', 'body' => 'Account removed, referral made.'])
            ->assertOk()->assertJsonPath('incident.status', 'closed');
        $this->actingAs($operator)->postJson(route('safety.operator.incidents.update', $id), ['action' => 'note', 'body' => 'late'])->assertOk();
        $this->actingAs($operator)->postJson(route('safety.operator.incidents.update', $id), ['action' => 'acknowledge'])->assertUnprocessable();

        $types = IncidentEvent::query()->where('incident_id', $id)->orderBy('id')->pluck('type')->all();
        $this->assertSame(['opened', 'note', 'referred', 'closed', 'note'], $types);
        $this->assertSame('CAS 123/11/2026', Incident::find($id)->external_reference);

        $this->actingAs($operator)->get(route('safety.operator.incidents.show', $id))->assertOk()->assertSee('Reported to SAPS');
        $this->actingAs($operator)->get(route('safety.operator.incidents.index'))->assertOk()->assertSee(Incident::find($id)->reference);
    }

    public function test_incident_events_are_append_only(): void
    {
        app(Incidents::class)->open('Concern', 'medium', $this->operator());

        $this->expectException(LogicException::class);
        IncidentEvent::sole()->delete();
    }

    public function test_non_operators_cannot_see_incidents(): void
    {
        $incident = app(Incidents::class)->open('Concern', 'medium', $this->operator());

        $this->actingAs($this->adult())->getJson(route('safety.operator.incidents.show', $incident))->assertForbidden();
    }
}
