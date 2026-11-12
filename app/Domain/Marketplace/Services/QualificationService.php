<?php

namespace App\Domain\Marketplace\Services;

use App\Domain\Marketplace\Enums\QualificationStatus;
use App\Domain\Marketplace\Exceptions\MarketplaceException;
use App\Domain\Marketplace\Models\QualificationEvidence;
use App\Domain\Marketplace\Models\TutorQualification;
use App\Models\TutorProfile;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

/**
 * The tutor's side of qualifications: declare, attach evidence, send for
 * review. A verified record is locked: the evidence behind a verification is
 * kept exactly as the operator saw it.
 */
class QualificationService
{
    public const MAX_EVIDENCE_FILES = 5;

    public function __construct(private PrivateFiles $files) {}

    /** @param  array<string, mixed>  $data */
    public function create(TutorProfile $profile, array $data): TutorQualification
    {
        return TutorQualification::create([...$data, 'tutor_profile_id' => $profile->id]);
    }

    /** @param  array<string, mixed>  $data */
    public function update(TutorQualification $qualification, array $data): TutorQualification
    {
        $this->assertEditable($qualification);

        return DB::transaction(function () use ($qualification, $data) {
            $qualification->fill($data);
            // Changing what was claimed after sending it means the review starts again.
            if ($qualification->isDirty() && $qualification->status !== QualificationStatus::Unverified->value) {
                $qualification->forceFill(['status' => QualificationStatus::Unverified->value, 'submitted_at' => null]);
            }
            $qualification->save();

            return $qualification;
        });
    }

    public function delete(TutorQualification $qualification): void
    {
        $this->assertEditable($qualification);

        DB::transaction(function () use ($qualification) {
            $qualification->evidence()->get()->each->delete();
            $qualification->delete();
        });
    }

    public function addEvidence(TutorQualification $qualification, UploadedFile $file, User $uploader): QualificationEvidence
    {
        $this->assertEditable($qualification);
        if ($qualification->evidence()->count() >= self::MAX_EVIDENCE_FILES) {
            throw new MarketplaceException('too_many_files', 'A qualification can carry at most '.self::MAX_EVIDENCE_FILES.' evidence files.', 422);
        }

        $stored = $this->files->storeEvidence($file, 'marketplace/evidence/'.$qualification->tutor_profile_id);

        return QualificationEvidence::create([
            'tutor_qualification_id' => $qualification->id,
            'uploaded_by' => $uploader->id,
            'path' => $stored['path'],
            'original_name' => mb_substr($file->getClientOriginalName(), 0, 200),
            'mime' => $stored['mime'],
            'size_bytes' => $stored['size_bytes'],
            'sha256' => $stored['sha256'],
        ]);
    }

    public function removeEvidence(QualificationEvidence $evidence): void
    {
        $this->assertEditable($evidence->qualification);
        $evidence->delete();
    }

    /** Send for review. Needs at least one evidence file. */
    public function submit(TutorQualification $qualification): TutorQualification
    {
        $this->assertEditable($qualification);
        if ($qualification->status === QualificationStatus::Submitted->value) {
            return $qualification;
        }
        if (! $qualification->evidence()->exists()) {
            throw new MarketplaceException('evidence_required', 'Attach at least one evidence file (a certificate, transcript, letter or registration) before sending this for review.', 422);
        }

        $qualification->forceFill([
            'status' => QualificationStatus::Submitted->value,
            'submitted_at' => now(),
        ])->save();

        return $qualification;
    }

    private function assertEditable(TutorQualification $qualification): void
    {
        if ($qualification->isVerified()) {
            throw new MarketplaceException('locked', 'A verified qualification cannot be changed. Add a new record instead.', 409);
        }
    }
}
