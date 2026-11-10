<?php

namespace App\Domain\Safety\Services;

use App\Domain\Safety\Audit;
use App\Domain\Safety\Contracts\Moderatable;
use App\Domain\Safety\Models\AccountRestriction;
use App\Domain\Safety\Models\ModerationAction;
use App\Domain\Safety\Models\Report;
use App\Domain\Safety\Notifications\AccountWarned;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Moderator actions: hide, restore, warn, suspend, unsuspend, escalate, dismiss. Each writes a moderation action
 * row and an audit entry, and resolves the report it came from.
 */
class Moderation
{
    public const ACTIONS = ['hide', 'restore', 'warn', 'suspend', 'unsuspend', 'escalate', 'dismiss'];

    public function __construct(private readonly Incidents $incidents) {}

    /** @param array<string, mixed> $meta (days: suspension length; severity: escalation severity) */
    public function act(User $operator, string $action, ?Report $report, ?Model $target, ?User $targetUser, string $reason, array $meta = []): ModerationAction
    {
        if (! in_array($action, self::ACTIONS, true)) {
            throw ValidationException::withMessages(['action' => 'Unknown moderation action.']);
        }

        $target ??= $report?->reportable;
        $targetUser ??= $report?->reportedUser;

        return DB::transaction(function () use ($operator, $action, $report, $target, $targetUser, $reason, $meta) {
            switch ($action) {
                case 'hide':
                case 'restore':
                    if (! $target instanceof Moderatable) {
                        throw ValidationException::withMessages(['action' => 'This kind of content cannot be hidden from here; its own area handles it.']);
                    }
                    $action === 'hide' ? $target->moderationHide($operator) : $target->moderationRestore($operator);
                    break;

                case 'warn':
                case 'suspend':
                    if ($targetUser === null) {
                        throw ValidationException::withMessages(['action' => 'There is no person to '.$action.'.']);
                    }
                    if ($targetUser->is($operator)) {
                        throw ValidationException::withMessages(['action' => 'You cannot act on your own account.']);
                    }
                    $days = isset($meta['days']) && (int) $meta['days'] > 0 ? (int) $meta['days'] : null;
                    AccountRestriction::create([
                        'user_id' => $targetUser->id,
                        'kind' => $action === 'warn' ? AccountRestriction::WARNING : AccountRestriction::SUSPENSION,
                        'reason' => mb_substr($reason, 0, 500),
                        'imposed_by_id' => $operator->id,
                        'starts_at' => now(),
                        'ends_at' => $action === 'suspend' && $days ? now()->addDays($days) : null,
                    ]);
                    $targetUser->notify(new AccountWarned($action === 'warn' ? 'warning' : 'suspension', $reason));
                    break;

                case 'unsuspend':
                    if ($targetUser === null) {
                        throw ValidationException::withMessages(['action' => 'There is no person to reinstate.']);
                    }
                    AccountRestriction::query()->inEffect()->where('user_id', $targetUser->id)
                        ->where('kind', AccountRestriction::SUSPENSION)
                        ->update(['lifted_at' => now(), 'lifted_by_id' => $operator->id]);
                    break;

                case 'escalate':
                    $severity = in_array($meta['severity'] ?? null, Incidents::SEVERITIES, true) ? $meta['severity'] : 'high';
                    if ($report?->incident_id === null) {
                        $this->incidents->open($reason, $severity, $operator, null, $report);
                    }
                    break;
            }

            $row = ModerationAction::create([
                'actor_id' => $operator->id,
                'report_id' => $report?->id,
                'incident_id' => $report?->fresh()?->incident_id,
                'action' => $action,
                'target_type' => $target?->getMorphClass(),
                'target_id' => $target?->getKey(),
                'target_user_id' => $targetUser?->id,
                'reason' => mb_substr($reason, 0, 500),
                'meta' => $meta === [] ? null : $meta,
                'created_at' => now(),
            ]);

            if ($report !== null && ! in_array($action, ['escalate', 'restore'], true)) {
                $report->forceFill([
                    'status' => $action === 'dismiss' ? Report::DISMISSED : Report::ACTIONED,
                    'resolved_at' => now(),
                    'resolution' => mb_substr($action.': '.$reason, 0, 500),
                    'assigned_to_id' => $report->assigned_to_id ?? $operator->id,
                ])->save();
            }

            Audit::record($operator, 'safety.moderation.'.$action, $target ?? $targetUser ?? $report, [
                'report_id' => $report?->id, 'target_user_id' => $targetUser?->id,
            ]);

            return $row;
        });
    }
}
