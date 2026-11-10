<?php

namespace App\Domain\Safety\Services;

use App\Domain\Safety\Audit;
use App\Domain\Safety\Enums\ScreeningKind;
use App\Domain\Safety\Models\ScreeningCheck;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * Tutor screening for work with minors, recorded by hand. An operator does the check outside the platform (for
 * example a Form 29 enquiry to the Department of Social Development for Part B of the Child Protection Register,
 * or a Form 8 enquiry to the National Register for Sex Offenders), records the outcome and evidence here, and a
 * second operator reviews it. Nothing here queries a register: neither offers a public lookup.
 */
class Screening
{
    /** @param array{checked_on: string, expires_on?: ?string, reference?: ?string, notes?: ?string} $data */
    public function record(User $tutor, ScreeningKind $kind, array $data, ?UploadedFile $evidence, User $operator): ScreeningCheck
    {
        $stored = null;

        if ($evidence !== null) {
            $stored = $evidence->store('screening/'.$tutor->id, config('safety.messaging.disk'));
        }

        $check = ScreeningCheck::create([
            'user_id' => $tutor->id,
            'kind' => $kind,
            'status' => ScreeningCheck::PENDING_REVIEW,
            'reference' => $data['reference'] ?? null,
            'checked_on' => $data['checked_on'],
            'expires_on' => $data['expires_on'] ?? null,
            'evidence_path' => $stored ?: null,
            'evidence_name' => $evidence ? mb_substr($evidence->getClientOriginalName(), 0, 200) : null,
            'evidence_sha256' => $evidence ? hash_file('sha256', $evidence->getRealPath()) : null,
            'notes' => $data['notes'] ?? null,
            'recorded_by_id' => $operator->id,
        ]);

        Audit::record($operator, 'safety.screening.recorded', $check, ['tutor_id' => $tutor->id, 'kind' => $kind->value]);

        return $check;
    }

    public function review(ScreeningCheck $check, User $reviewer, bool $cleared, ?string $note = null): ScreeningCheck
    {
        if ($check->status !== ScreeningCheck::PENDING_REVIEW) {
            throw ValidationException::withMessages(['check' => 'This check has already been reviewed.']);
        }

        if (config('safety.screening.four_eyes') && $check->recorded_by_id === $reviewer->id) {
            throw ValidationException::withMessages(['check' => 'A different operator must review a screening record.']);
        }

        if ($cleared && $check->evidence_path === null) {
            throw ValidationException::withMessages(['check' => 'A check cannot be cleared without evidence attached.']);
        }

        $check->forceFill([
            'status' => $cleared ? ScreeningCheck::CLEARED : ScreeningCheck::NOT_CLEARED,
            'reviewed_by_id' => $reviewer->id,
            'reviewed_at' => now(),
            'review_note' => $note,
        ])->save();

        Audit::record($reviewer, $cleared ? 'safety.screening.cleared' : 'safety.screening.not_cleared', $check, [
            'tutor_id' => $check->user_id, 'kind' => $check->kind->value,
        ]);

        return $check;
    }

    public function revoke(ScreeningCheck $check, User $operator, string $reason): void
    {
        $check->forceFill(['status' => ScreeningCheck::REVOKED, 'review_note' => mb_substr($reason, 0, 500)])->save();

        Audit::record($operator, 'safety.screening.revoked', $check, ['tutor_id' => $check->user_id]);
    }

    /** @return list<string> required kinds without a current cleared check */
    public function missingKinds(User $tutor): array
    {
        $required = (array) config('safety.screening.required_kinds');

        $current = ScreeningCheck::query()->where('user_id', $tutor->id)->where('status', ScreeningCheck::CLEARED)->get()
            ->filter->isCurrent()->map(fn (ScreeningCheck $c) => $c->kind->value)->unique()->all();

        return array_values(array_diff($required, $current));
    }

    public function isClearedForMinors(User $tutor): bool
    {
        return $this->missingKinds($tutor) === [];
    }

    public function evidenceDisk(): \Illuminate\Contracts\Filesystem\Filesystem
    {
        return Storage::disk(config('safety.messaging.disk'));
    }
}
