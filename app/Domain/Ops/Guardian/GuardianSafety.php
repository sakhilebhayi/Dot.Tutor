<?php

namespace App\Domain\Ops\Guardian;

use App\Domain\Safety\Enums\ReportPriority;
use App\Domain\Safety\Models\AccountRestriction;
use App\Domain\Safety\Models\Conversation;
use App\Domain\Safety\Models\ConversationParticipant;
use App\Domain\Safety\Models\GuardianLink;
use App\Domain\Safety\Models\Incident;
use App\Domain\Safety\Models\Message;
use App\Domain\Safety\Models\Report;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * The guardian's safety page (D-2101): one row per report, safeguarding case or account notice about one of the
 * guardian's own linked children, in the words a parent needs: what, when, where it stands, what happens next and
 * what they can do. Reports come from the same scope the safety domain's guardian dashboard uses (filed by the child,
 * about the child, or about a message in the child's conversations), plus safeguarding cases opened about the child.
 *
 * Minors' data is minimised: no report text, no snapshot, no officer notes, no case outcome and never the name of
 * anyone else who filed a report. A child's own report says the child filed it; every other report says only where
 * it was made.
 */
class GuardianSafety
{
    private const OPEN_INCIDENT = [Incident::OPEN, Incident::ACKNOWLEDGED, Incident::REFERRED];

    /**
     * Rows for every child of this guardian, urgent and open first, then newest.
     *
     * @return list<array<string, mixed>>
     */
    public function rowsFor(User $guardian): array
    {
        $links = GuardianLink::query()->active()->where('guardian_id', $guardian->id)->with('learner')->orderBy('id')->get();

        return $this->sorted($links->flatMap(fn (GuardianLink $link) => $link->learner ? $this->rowsForChild($link->learner, $guardian) : [])->all());
    }

    /**
     * Urgent and open first, then open, then newest.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    public function sorted(array $rows): array
    {
        return collect($rows)->sortBy([
            fn (array $a, array $b) => [$b['urgent'] && $b['open'], $b['open']] <=> [$a['urgent'] && $a['open'], $a['open']],
            fn (array $a, array $b) => $b['at'] <=> $a['at'],
        ])->values()->all();
    }

    /** @return list<array<string, mixed>> */
    public function rowsForChild(User $child, User $guardian): array
    {
        $conversationIds = Conversation::query()
            ->whereHas('participants', fn ($q) => $q->where('user_id', $child->id)->where('role', ConversationParticipant::MEMBER))
            ->pluck('id');
        $messageIds = Message::query()->whereIn('conversation_id', $conversationIds)->select('id');
        $guardianIn = ConversationParticipant::query()->where('user_id', $guardian->id)->whereIn('conversation_id', $conversationIds)->pluck('conversation_id')->flip();

        $reports = Report::query()->with('incident')
            ->where(fn ($q) => $q->where('reporter_id', $child->id)->orWhere('reported_user_id', $child->id)
                ->orWhere(fn ($q) => $q->where('reportable_type', (new Message)->getMorphClass())->whereIn('reportable_id', $messageIds)))
            ->orderByDesc('id')->limit(50)->get();

        $rows = $reports->map(fn (Report $r) => $this->reportRow($r, $child, $guardianIn))->all();

        $shownIncidents = $reports->pluck('incident_id')->filter()->all();
        $incidents = Incident::query()->where('learner_id', $child->id)->whereNotIn('id', $shownIncidents)->orderByDesc('id')->limit(20)->get();
        foreach ($incidents as $incident) {
            $rows[] = $this->incidentRow($incident, $child);
        }

        $restrictions = AccountRestriction::query()->where('user_id', $child->id)->orderByDesc('id')->limit(10)->get();
        foreach ($restrictions as $restriction) {
            $rows[] = $this->restrictionRow($restriction, $child);
        }

        return $this->sorted($rows);
    }

    /** Rows that need the guardian's attention now: urgent and not closed. */
    public function urgent(array $rows): array
    {
        return array_values(array_filter($rows, fn (array $row) => $row['urgent'] && $row['open']));
    }

    /**
     * @param  Collection<int, int>  $guardianIn  conversation id => index, for conversations the guardian is in
     * @return array<string, mixed>
     */
    private function reportRow(Report $r, User $child, Collection $guardianIn): array
    {
        $incident = $r->incident;
        $incidentOpen = $incident !== null && in_array($incident->status, self::OPEN_INCIDENT, true);
        $open = $r->isOpen() || $incidentOpen;
        $urgent = $r->category->isChildSafety()
            || in_array($r->priority, [ReportPriority::Critical, ReportPriority::High], true)
            || $r->status === Report::ESCALATED
            || ($incident !== null && in_array($incident->severity, ['critical', 'high'], true));

        [$status, $tone, $next] = match (true) {
            $incident !== null && $incident->status === Incident::OPEN => [
                'Being looked at urgently', 'danger',
                $this->openIncidentNext($incident),
            ],
            $incident !== null && $incident->status === Incident::ACKNOWLEDGED => [
                'A safeguarding officer is handling it', $urgent ? 'danger' : 'warning',
                'The officer looks into what happened and decides what to do. This page shows when the case is closed.',
            ],
            $incident !== null && $incident->status === Incident::REFERRED => [
                'Referred outside Dot.Tutor', $urgent ? 'danger' : 'warning',
                'A safeguarding officer referred this to an outside authority, for example the police or social services. The case stays open here until it is closed.',
            ],
            $incident !== null && $incident->status === Incident::CLOSED && ! $r->isOpen() => [
                'Closed', 'neutral', 'A safeguarding officer closed the case.',
            ],
            $r->status === Report::ESCALATED => [
                'Being looked at urgently', 'danger',
                $r->respond_by?->isPast()
                    ? 'It was passed to a senior safeguarding officer, who is overdue: they should have looked at it'.$this->by($r->respond_by).'.'
                    : 'It was passed to a senior safeguarding officer'.$this->by($r->respond_by).'.',
            ],
            $r->status === Report::OPEN => [
                'Waiting for a safety officer', $urgent ? 'danger' : 'warning',
                $r->respond_by?->isPast()
                    ? 'Overdue: a safety officer should have looked at it'.$this->by($r->respond_by).'.'
                    : 'A safety officer is due to look at it'.$this->by($r->respond_by).'.',
            ],
            $r->status === Report::ACTIONED => [
                'Acted on', 'success', 'A safety officer reviewed it and took action.',
            ],
            default => [
                'Closed, no action needed', 'neutral', 'A safety officer reviewed it and found nothing against the rules. If something new happens, report it again.',
            ],
        };

        $where = match (true) {
            $r->reporter_id === $child->id => $child->name.' reported it',
            $r->reportable_type === (new Message)->getMorphClass() => 'Reported in a conversation '.$child->name.' is in',
            $r->reported_user_id === $child->id => 'A report about '.$child->name,
            default => 'A report involving '.$child->name,
        };

        $actions = [];
        if ($r->reportable_type === (new Message)->getMorphClass()) {
            $conversationId = Message::query()->whereKey($r->reportable_id)->value('conversation_id');
            if ($conversationId !== null && $guardianIn->has($conversationId)) {
                $actions[] = ['label' => 'Read the conversation', 'href' => route('safety.conversations.show', $conversationId)];
            }
        }

        return [
            'key' => 'report-'.$r->id,
            'kind' => 'report',
            'child_id' => $child->id,
            'child' => $child->name,
            'what' => $r->category->label(),
            'where' => $where,
            'at' => $r->created_at,
            'status' => $status,
            'tone' => $tone,
            'next' => $next,
            'urgent' => $urgent,
            'open' => $open,
            'actions' => $actions,
        ];
    }

    /** @return array<string, mixed> */
    private function incidentRow(Incident $incident, User $child): array
    {
        $open = in_array($incident->status, self::OPEN_INCIDENT, true);
        [$status, $next] = match ($incident->status) {
            Incident::OPEN => ['Being looked at urgently', $this->openIncidentNext($incident)],
            Incident::ACKNOWLEDGED => ['A safeguarding officer is handling it', 'The officer looks into what happened and decides what to do. This page shows when the case is closed.'],
            Incident::REFERRED => ['Referred outside Dot.Tutor', 'A safeguarding officer referred this to an outside authority, for example the police or social services.'],
            default => ['Closed', 'A safeguarding officer closed the case.'],
        };
        $urgent = in_array($incident->severity, ['critical', 'high'], true);

        return [
            'key' => 'case-'.$incident->id,
            'kind' => 'case',
            'child_id' => $child->id,
            'child' => $child->name,
            'what' => 'A safeguarding case about '.$child->name,
            'where' => 'Opened by a Dot.Tutor safeguarding officer, reference '.$incident->reference,
            'at' => $incident->created_at,
            'status' => $status,
            'tone' => $open ? ($urgent ? 'danger' : 'warning') : 'neutral',
            'next' => $next,
            'urgent' => $urgent,
            'open' => $open,
            'actions' => [],
        ];
    }

    /** @return array<string, mixed> */
    private function restrictionRow(AccountRestriction $r, User $child): array
    {
        $suspension = $r->kind === 'suspension';
        $active = $r->lifted_at === null && ($r->ends_at === null || $r->ends_at->isFuture());
        $status = match (true) {
            $r->lifted_at !== null => 'Lifted',
            ! $suspension => 'On the account',
            $active => $r->ends_at ? 'Until '.$r->ends_at->timezone(config('tutor.timezone'))->format('j M Y') : 'Until an officer lifts it',
            default => 'Ended',
        };

        return [
            'key' => 'notice-'.$r->id,
            'kind' => 'notice',
            'child_id' => $child->id,
            'child' => $child->name,
            'what' => ($suspension ? 'Suspension of ' : 'Warning to ').$child->name.($r->reason ? ': '.$r->reason : ''),
            'where' => 'Given by a Dot.Tutor safety officer',
            'at' => $r->starts_at ?? $r->created_at,
            'status' => $status,
            'tone' => $suspension && $active ? 'danger' : ($active ? 'warning' : 'neutral'),
            'next' => $suspension && $active
                ? $child->name.' cannot book, pay, message or join rooms while it lasts.'
                : ($active ? 'The warning stays on the account.' : 'Nothing more is needed.'),
            'urgent' => $suspension && $active,
            'open' => $active,
            'actions' => [],
        ];
    }

    /**
     * What happens next on a case nobody has taken on yet, said as what is true now (fix round D-2308): a deadline
     * still ahead is a promise; one that passed is "overdue"; an automatic escalation says when it happened.
     */
    private function openIncidentNext(Incident $incident): string
    {
        $due = $incident->respond_by;
        $escalatedAt = (int) $incident->escalation_level > 0
            ? $incident->events()->where('type', 'escalated')->reorder()->orderByDesc('id')->value('created_at')
            : null;
        $raised = $escalatedAt !== null
            ? 'Nobody took it on in time, so it was raised to more senior staff at '.$this->clock(\Illuminate\Support\Carbon::parse($escalatedAt)).'. '
            : '';

        if ($due !== null && $due->isPast()) {
            return $raised.'Overdue: a safeguarding officer should have taken this on'.$this->by($due).'. It is being raised to more senior staff now.';
        }

        return $raised.($raised === '' ? 'A safeguarding officer must take this on' : 'A senior safeguarding officer must take it on')
            .$this->by($due).'.'.($raised === '' ? ' If nobody does, it is raised to more senior staff automatically.' : '');
    }

    private function clock(CarbonInterface $at): string
    {
        return $at->copy()->timezone(config('tutor.timezone'))->format('H:i \o\n D j M');
    }

    private function by(?CarbonInterface $at): string
    {
        return $at ? ' by '.$at->copy()->timezone(config('tutor.timezone'))->format('H:i \o\n D j M') : '';
    }
}
