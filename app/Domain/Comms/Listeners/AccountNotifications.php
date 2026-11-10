<?php

namespace App\Domain\Comms\Listeners;

use App\Domain\Comms\CommsMessage;
use App\Domain\Comms\Messenger;
use App\Domain\Marketplace\Enums\VerificationLevel;
use App\Domain\Marketplace\Models\TutorQualification;
use App\Domain\Marketplace\Models\VerificationDecision;
use App\Domain\Safety\Models\Report;
use App\Models\TutorProfile;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Verification decisions (marketplace's append-only rows) and the outcome of reports a person filed (safety's
 * report rows). Thin, read-only observers that run after commit. The reporter is told that their report was
 * reviewed and whether action was taken, never what happened to the other person.
 */
final class AccountNotifications
{
    public bool $afterCommit = true;

    public function __construct(private readonly Messenger $m) {}

    public function verificationDecided(VerificationDecision $decision): void
    {
        try {
            $tutor = User::find(TutorProfile::query()->whereKey($decision->tutor_profile_id)->value('user_id'));

            if ($tutor === null) {
                return;
            }

            $reason = trim((string) $decision->reason) !== '' ? ' Note from the reviewer: '.trim((string) $decision->reason) : '';

            if ($decision->subject === 'qualification') {
                $title = (string) (TutorQualification::query()->whereKey($decision->tutor_qualification_id)->value('title') ?? 'your qualification');
                [$heading, $body] = match ($decision->to_value) {
                    'verified' => ['Qualification verified', '"'.$title.'" was checked and now shows as verified by Dot.Tutor on your profile.'],
                    'rejected' => ['Qualification not verified', '"'.$title.'" could not be verified from the evidence given, so it is not shown.'.$reason],
                    default => ['Qualification status changed', '"'.$title.'" is now '.str_replace('_', ' ', (string) $decision->to_value).'.'.$reason],
                };
            } else {
                $level = $decision->to_value ? VerificationLevel::tryFrom((string) $decision->to_value) : null;
                [$heading, $body] = $level !== null
                    ? ['Verification level updated', 'Your profile now shows: '.$level->label().'.']
                    : ['Verification level removed', 'Your profile no longer shows a verification level.'.$reason];
            }

            $this->m->send(new CommsMessage($tutor, 'tutor_verification_decided', 'verification:'.$decision->id, $heading, $body,
                route('tutor.profile.edit', absolute: false), 'verification-'.$decision->tutor_profile_id, $decision->operator_id,
                data: ['tutor_profile_id' => $decision->tutor_profile_id]));
        } catch (Throwable $e) {
            Log::warning('Comms could not notify a verification decision.', ['decision_id' => $decision->id, 'error' => class_basename($e)]);
        }
    }

    public function reportUpdated(Report $report): void
    {
        try {
            if (! $report->wasChanged('status') || ! in_array($report->status, [Report::ACTIONED, Report::DISMISSED], true) || $report->reporter_id === null) {
                return;
            }

            $reporter = User::find($report->reporter_id);

            if ($reporter === null) {
                return;
            }

            $body = $report->status === Report::ACTIONED
                ? 'Thank you. A moderator reviewed your report and took action. For privacy we do not share what was done.'
                : 'Thank you. A moderator reviewed your report and found nothing that breaks the rules. If something else happens, report it again.';

            $this->m->learnerAndGuardians($reporter,
                new CommsMessage($reporter, 'report_updated', 'report:'.$report->id.':'.$report->status, 'Your report was reviewed', $body,
                    route('safety.account', absolute: false), 'report-'.$report->id, null, data: ['report_id' => $report->id]),
                fn (User $g) => ['A report by '.$this->m->firstName($reporter).' was reviewed', $body]);
        } catch (Throwable $e) {
            Log::warning('Comms could not notify a report outcome.', ['report_id' => $report->id, 'error' => class_basename($e)]);
        }
    }
}
