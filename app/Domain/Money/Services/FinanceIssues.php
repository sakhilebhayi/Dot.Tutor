<?php

namespace App\Domain\Money\Services;

use App\Domain\Money\Models\FinanceIssue;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Raise an item on the finance exceptions list once (dedupe key), and resolve it with a note.
 */
class FinanceIssues
{
    /**
     * @param  array{severity?: string, subject_type?: ?string, subject_id?: ?int, amount_minor?: ?int, currency?: ?string, details?: array<string, mixed>}  $attributes
     */
    public function raise(string $kind, string $dedupeKey, string $summary, array $attributes = []): FinanceIssue
    {
        $existing = FinanceIssue::where('dedupe_key', $dedupeKey)->first();

        if ($existing !== null) {
            return $existing;
        }

        try {
            return DB::transaction(fn () => FinanceIssue::create([
                'kind' => $kind,
                'dedupe_key' => mb_substr($dedupeKey, 0, 160),
                'summary' => mb_substr($summary, 0, 255),
                'severity' => $attributes['severity'] ?? 'warning',
                'subject_type' => $attributes['subject_type'] ?? null,
                'subject_id' => $attributes['subject_id'] ?? null,
                'amount_minor' => $attributes['amount_minor'] ?? null,
                'currency' => $attributes['currency'] ?? null,
                'details' => $attributes['details'] ?? null,
                'status' => 'open',
            ]));
        } catch (UniqueConstraintViolationException) {
            return FinanceIssue::where('dedupe_key', $dedupeKey)->firstOrFail();
        }
    }

    public function resolve(FinanceIssue $issue, User $actor, string $note): FinanceIssue
    {
        $issue->forceFill([
            'status' => 'resolved',
            'resolved_by' => $actor->id,
            'resolved_at' => now(),
            'resolution_note' => mb_substr($note, 0, 500),
        ])->save();

        return $issue;
    }
}
