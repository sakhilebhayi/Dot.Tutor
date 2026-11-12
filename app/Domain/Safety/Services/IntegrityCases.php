<?php

namespace App\Domain\Safety\Services;

use App\Domain\Safety\Audit;
use App\Domain\Safety\Enums\IntegrityKind;
use App\Domain\Safety\Models\IntegrityCase;
use App\Domain\Safety\Models\Report;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

/** Academic integrity cases under the policy in docs/R47_SAFETY.md section 9. */
class IntegrityCases
{
    public function open(IntegrityKind $kind, string $summary, ?User $openedBy, ?Model $subject = null, ?User $subjectUser = null, ?Report $report = null): IntegrityCase
    {
        $case = IntegrityCase::create([
            'kind' => $kind,
            'subject_type' => $subject?->getMorphClass(),
            'subject_id' => $subject?->getKey(),
            'subject_user_id' => $subjectUser?->id,
            'report_id' => $report?->id,
            'opened_by_id' => $openedBy?->id ?? $report?->reporter_id,
            'status' => IntegrityCase::OPEN,
            'summary' => $summary,
        ]);

        Audit::record($openedBy, 'safety.integrity.opened', $case, ['kind' => $kind->value, 'report_id' => $report?->id]);

        return $case;
    }

    public function decide(IntegrityCase $case, User $operator, bool $upheld, string $finding, string $sanction = 'none', ?int $suspendDays = null): IntegrityCase
    {
        if ($case->status !== IntegrityCase::OPEN) {
            throw ValidationException::withMessages(['case' => 'This case has already been decided.']);
        }

        if (! in_array($sanction, IntegrityCase::SANCTIONS, true) || (! $upheld && $sanction !== 'none')) {
            throw ValidationException::withMessages(['sanction' => 'Choose a sanction only when the case is upheld.']);
        }

        $case->forceFill([
            'status' => $upheld ? IntegrityCase::UPHELD : IntegrityCase::NOT_UPHELD,
            'finding' => $finding,
            'sanction' => $sanction,
            'decided_by_id' => $operator->id,
            'decided_at' => now(),
        ])->save();

        $subjectUser = $case->subject_user_id ? User::find($case->subject_user_id) : null;

        if ($upheld && $subjectUser && in_array($sanction, ['warning', 'suspension'], true)) {
            app(Moderation::class)->act($operator, $sanction === 'warning' ? 'warn' : 'suspend', null, null, $subjectUser,
                'Academic integrity: '.$case->kind->label().'. '.$finding, ['days' => $suspendDays, 'integrity_case_id' => $case->id]);
        }

        Audit::record($operator, 'safety.integrity.decided', $case, ['upheld' => $upheld, 'sanction' => $sanction]);

        return $case;
    }
}
