<?php

namespace App\Domain\Safety\Services;

use App\Domain\Safety\Audit;
use App\Domain\Safety\Models\Incident;
use App\Domain\Safety\Models\IncidentEvent;
use App\Domain\Safety\Models\Report;
use App\Domain\Safety\Notifications\IncidentAlert;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Safeguarding incidents: opened from a child-safety report (automatically) or by an operator, with a response
 * timer. An unacknowledged incident escalates a level each time its timer runs out (safety:escalate-incidents,
 * every five minutes) and every operator is told. Referral to an outside body is recorded by hand.
 */
class Incidents
{
    public const SEVERITIES = ['critical', 'high', 'medium'];

    public function open(string $title, string $severity, ?User $openedBy, ?User $learner = null, ?Report $report = null): Incident
    {
        $incident = DB::transaction(function () use ($title, $severity, $openedBy, $learner, $report) {
            $incident = Incident::create([
                'reference' => $this->newReference(),
                'title' => mb_substr($title, 0, 200),
                'severity' => $severity,
                'status' => Incident::OPEN,
                'opened_by_id' => $openedBy?->id,
                'learner_id' => $learner?->id,
                'respond_by' => now()->addMinutes($this->minutesFor($severity)),
            ]);

            $this->event($incident, $openedBy, 'opened', $report ? 'Opened from report #'.$report->id.'.' : null);

            if ($report !== null) {
                $report->forceFill(['incident_id' => $incident->id, 'status' => Report::ESCALATED])->save();
            }

            return $incident;
        });

        $this->alertOperators($incident, 'opened');
        Audit::record($openedBy, 'safety.incident.opened', $incident, ['severity' => $severity, 'report_id' => $report?->id]);

        return $incident;
    }

    public function attachReport(Incident $incident, Report $report, User $by): void
    {
        $report->forceFill(['incident_id' => $incident->id, 'status' => Report::ESCALATED])->save();
        $this->event($incident, $by, 'report_linked', 'Report #'.$report->id.' linked.');
    }

    public function acknowledge(Incident $incident, User $operator): Incident
    {
        $this->assertOpen($incident);

        $incident->forceFill([
            'status' => $incident->status === Incident::OPEN ? Incident::ACKNOWLEDGED : $incident->status,
            'owner_id' => $operator->id,
            'acknowledged_at' => $incident->acknowledged_at ?? now(),
            'respond_by' => null,
        ])->save();

        $this->event($incident, $operator, 'acknowledged', null);
        Audit::record($operator, 'safety.incident.acknowledged', $incident);

        return $incident;
    }

    public function note(Incident $incident, User $operator, string $body): void
    {
        $this->event($incident, $operator, 'note', $body);
        Audit::record($operator, 'safety.incident.note', $incident);
    }

    /** Record that the matter was referred outside (SAPS, DSD, the FPB hotline). Nothing is sent from here. */
    public function refer(Incident $incident, User $operator, string $body, ?string $externalReference): Incident
    {
        $this->assertOpen($incident);

        $incident->forceFill([
            'status' => Incident::REFERRED,
            'external_reference' => $externalReference,
            'acknowledged_at' => $incident->acknowledged_at ?? now(),
            'owner_id' => $incident->owner_id ?? $operator->id,
            'respond_by' => null,
        ])->save();

        $this->event($incident, $operator, 'referred', $body);
        Audit::record($operator, 'safety.incident.referred', $incident, ['external_reference' => $externalReference]);

        return $incident;
    }

    public function close(Incident $incident, User $operator, string $outcome): Incident
    {
        $this->assertOpen($incident);

        DB::transaction(function () use ($incident, $operator, $outcome) {
            $incident->forceFill(['status' => Incident::CLOSED, 'closed_at' => now(), 'outcome' => $outcome, 'respond_by' => null])->save();
            $incident->reports()->whereIn('status', [Report::OPEN, Report::ESCALATED])->update([
                'status' => Report::ACTIONED, 'resolved_at' => now(), 'resolution' => 'Closed with incident '.$incident->reference.'.',
            ]);
            $this->event($incident, $operator, 'closed', $outcome);
        });

        Audit::record($operator, 'safety.incident.closed', $incident);

        return $incident;
    }

    /** Escalate every unacknowledged incident whose timer has run out. Returns how many escalated. */
    public function escalateOverdue(): int
    {
        $count = 0;

        Incident::query()->where('status', Incident::OPEN)->whereNotNull('respond_by')->where('respond_by', '<=', now())
            ->orderBy('id')->get()
            ->each(function (Incident $incident) use (&$count) {
                $level = min($incident->escalation_level + 1, (int) config('safety.incidents.max_level'));

                $incident->forceFill([
                    'escalation_level' => $level,
                    'respond_by' => now()->addMinutes($this->minutesFor($incident->severity)),
                ])->save();

                $this->event($incident, null, 'escalated', 'Not acknowledged in time; escalated to level '.$level.'.');
                $this->alertOperators($incident, 'escalated');
                Audit::record(null, 'safety.incident.escalated', $incident, ['level' => $level]);
                $count++;
            });

        return $count;
    }

    private function event(Incident $incident, ?User $actor, string $type, ?string $body): void
    {
        IncidentEvent::create([
            'incident_id' => $incident->id,
            'actor_id' => $actor?->id,
            'type' => $type,
            'body' => $body,
            'created_at' => now(),
        ]);
    }

    private function alertOperators(Incident $incident, string $event): void
    {
        $operators = User::query()->where('is_platform_operator', true)->get();

        if ($operators->isNotEmpty()) {
            Notification::send($operators, new IncidentAlert($incident, $event));
        }
    }

    private function minutesFor(string $severity): int
    {
        return (int) config('safety.incidents.respond_within_minutes.'.$severity, 1440);
    }

    private function assertOpen(Incident $incident): void
    {
        if (! $incident->isOpen()) {
            throw ValidationException::withMessages(['incident' => 'This incident is closed.']);
        }
    }

    private function newReference(): string
    {
        do {
            $reference = 'INC-'.now()->format('Ymd').'-'.Str::upper(Str::random(5));
        } while (Incident::query()->where('reference', $reference)->exists());

        return $reference;
    }
}
