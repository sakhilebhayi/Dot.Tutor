<?php

namespace App\Domain\Marketplace\Services;

use App\Domain\Marketplace\Contracts\AuditPort;
use App\Domain\Marketplace\Enums\QualificationStatus;
use App\Domain\Marketplace\Enums\VerificationLevel;
use App\Domain\Marketplace\Exceptions\MarketplaceException;
use App\Domain\Marketplace\Models\TutorQualification;
use App\Domain\Marketplace\Models\VerificationDecision;
use App\Models\TutorProfile;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * The operator's side (brief section 16): review evidence, verify or reject a
 * qualification, assign a verification level. Every decision writes an
 * append-only VerificationDecision and goes to the platform audit seam.
 * A level can only be assigned when a verified qualification of a supporting
 * kind exists, so no label can ever run ahead of the evidence.
 */
class VerificationService
{
    public function __construct(private AuditPort $audit) {}

    public function verifyQualification(TutorQualification $qualification, User $operator, string $note): TutorQualification
    {
        return $this->decide($qualification, $operator, QualificationStatus::Verified, $note);
    }

    public function rejectQualification(TutorQualification $qualification, User $operator, string $reason): TutorQualification
    {
        return $this->decide($qualification, $operator, QualificationStatus::Rejected, $reason);
    }

    /**
     * Withdraw a verification (the evidence turned out to be false or expired).
     * A level that rested only on this record is removed in the same step.
     */
    public function revokeQualification(TutorQualification $qualification, User $operator, string $reason): TutorQualification
    {
        return $this->decide($qualification, $operator, QualificationStatus::Rejected, $reason, QualificationStatus::Verified);
    }

    public function assignLevel(TutorProfile $profile, ?VerificationLevel $level, User $operator, string $reason): TutorProfile
    {
        $this->assertNotSelf($profile, $operator);

        return DB::transaction(function () use ($profile, $level, $operator, $reason) {
            $locked = TutorProfile::query()->whereKey($profile->id)->lockForUpdate()->firstOrFail();

            $supporting = [];
            if ($level !== null) {
                $supporting = $locked->qualificationRecords()
                    ->where('status', QualificationStatus::Verified->value)
                    ->whereIn('kind', array_map(fn ($k) => $k->value, $level->supportingKinds()))
                    ->pluck('id')->all();
                if ($supporting === []) {
                    $kinds = implode(', ', array_map(fn ($k) => strtolower($k->label()), $level->supportingKinds()));
                    throw new MarketplaceException('evidence_missing', "No verified evidence supports \"{$level->label()}\". Verify a qualification of one of these kinds first: {$kinds}.", 422);
                }
            }

            $from = $locked->verification_level;
            $locked->forceFill([
                'verification_level' => $level?->value,
                'verification_level_set_by' => $operator->id,
                'verification_level_set_at' => now(),
            ])->save();

            VerificationDecision::create([
                'tutor_profile_id' => $locked->id,
                'operator_id' => $operator->id,
                'subject' => 'level',
                'from_value' => $from,
                'to_value' => $level?->value,
                'reason' => $reason,
                'evidence_ids' => $supporting,
            ]);
            $this->audit->record($operator, 'marketplace.verification.level', 'tutor_profile', $locked->id, ['from' => $from, 'to' => $level?->value, 'qualification_ids' => $supporting]);

            return $locked;
        });
    }

    private function decide(TutorQualification $qualification, User $operator, QualificationStatus $to, string $note, QualificationStatus $from = QualificationStatus::Submitted): TutorQualification
    {
        $this->assertNotSelf($qualification->tutorProfile, $operator);

        return DB::transaction(function () use ($qualification, $operator, $to, $note, $from) {
            $locked = TutorQualification::query()->whereKey($qualification->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== $from->value) {
                throw new MarketplaceException('wrong_state', $from === QualificationStatus::Submitted
                    ? 'Only a qualification sent for review with evidence can be decided.'
                    : 'Only a verified qualification can be revoked.', 409);
            }

            $previous = $locked->status;
            $locked->forceFill([
                'status' => $to->value,
                'reviewed_by' => $operator->id,
                'reviewed_at' => now(),
                'review_note' => $note,
            ])->save();

            $evidenceIds = $locked->evidence()->pluck('id')->all();
            VerificationDecision::create([
                'tutor_profile_id' => $locked->tutor_profile_id,
                'operator_id' => $operator->id,
                'subject' => 'qualification',
                'tutor_qualification_id' => $locked->id,
                'from_value' => $previous,
                'to_value' => $to->value,
                'reason' => $note,
                'evidence_ids' => $evidenceIds,
            ]);
            $this->audit->record($operator, 'marketplace.verification.qualification', 'tutor_qualification', $locked->id, ['from' => $previous, 'to' => $to->value, 'evidence_ids' => $evidenceIds]);

            // A revoked record can no longer support a level that rested on it alone.
            if ($previous === QualificationStatus::Verified->value) {
                $this->dropUnsupportedLevel($locked, $operator);
            }

            return $locked;
        });
    }

    private function dropUnsupportedLevel(TutorQualification $qualification, User $operator): void
    {
        $profile = TutorProfile::query()->whereKey($qualification->tutor_profile_id)->lockForUpdate()->firstOrFail();
        $level = $profile->verificationLevel();
        if ($level === null) {
            return;
        }

        $stillSupported = $profile->qualificationRecords()
            ->where('status', QualificationStatus::Verified->value)
            ->whereIn('kind', array_map(fn ($k) => $k->value, $level->supportingKinds()))
            ->exists();
        if ($stillSupported) {
            return;
        }

        $profile->forceFill(['verification_level' => null, 'verification_level_set_by' => $operator->id, 'verification_level_set_at' => now()])->save();
        VerificationDecision::create([
            'tutor_profile_id' => $profile->id,
            'operator_id' => $operator->id,
            'subject' => 'level',
            'from_value' => $level->value,
            'to_value' => null,
            'reason' => 'Level removed automatically: the qualification that supported it was rejected.',
            'evidence_ids' => [$qualification->id],
        ]);
    }

    private function assertNotSelf(TutorProfile $profile, User $operator): void
    {
        if ($profile->user_id === $operator->id) {
            throw new MarketplaceException('conflict_of_interest', 'Operators cannot review their own tutor profile.', 403);
        }
    }
}
