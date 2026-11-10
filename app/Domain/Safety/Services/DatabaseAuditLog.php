<?php

namespace App\Domain\Safety\Services;

use App\Domain\Safety\Contracts\AuditLog;
use App\Domain\Safety\Models\AuditEntry;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Hash-chained, append-only. hash = sha256(prev_hash | action | actor | subject | context json | created_at).
 * The chain shows edits and deletions made outside the model (verify()); it does not stop someone with database
 * access from rewriting the whole chain, so the deploy backups remain the second copy.
 */
class DatabaseAuditLog implements AuditLog
{
    public function record(?User $actor, string $action, Model|string|null $subject = null, array $context = []): void
    {
        [$subjectType, $subjectId] = match (true) {
            $subject instanceof Model => [$subject->getMorphClass(), $subject->getKey()],
            is_string($subject) => [$subject, null],
            default => [null, null],
        };

        DB::transaction(function () use ($actor, $action, $subjectType, $subjectId, $context) {
            // Serialise writers so two rows never chain onto the same predecessor. PostgreSQL's FOR UPDATE does
            // not block the insert of a new last row, so it takes a transaction-scoped advisory lock; MySQL's
            // next-key lock and SQLite's single writer already serialise.
            if (DB::getDriverName() === 'pgsql') {
                DB::select('select pg_advisory_xact_lock(470400)');
            }

            $previous = AuditEntry::query()->orderByDesc('id')->lockForUpdate()->value('hash');
            $createdAt = Carbon::now()->startOfSecond();

            $row = [
                'actor_id' => $actor?->id,
                'actor_label' => $actor ? 'user:'.$actor->id : 'system',
                'action' => $action,
                'subject_type' => $subjectType,
                'subject_id' => $subjectId,
                'context' => $context === [] ? null : $context,
                'prev_hash' => $previous,
                'created_at' => $createdAt,
            ];
            $row['hash'] = self::hashOf($row);

            AuditEntry::create($row);
        });
    }

    /** @param array<string, mixed> $row */
    public static function hashOf(array $row): string
    {
        $context = $row['context'] ?? null;

        return hash('sha256', implode('|', [
            $row['prev_hash'] ?? '',
            $row['action'],
            $row['actor_id'] ?? '',
            $row['subject_type'] ?? '',
            $row['subject_id'] ?? '',
            $context === null ? '' : json_encode(self::sortKeys($context), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            Carbon::parse($row['created_at'])->utc()->format('Y-m-d\TH:i:s\Z'),
        ]));
    }

    /**
     * Walk the chain; returns the id of the first broken row, or null when intact.
     */
    public function verify(): ?int
    {
        $previous = null;
        $broken = null;

        AuditEntry::query()->orderBy('id')->chunk(500, function ($entries) use (&$previous, &$broken) {
            foreach ($entries as $entry) {
                $row = [
                    'prev_hash' => $entry->prev_hash,
                    'action' => $entry->action,
                    'actor_id' => $entry->actor_id,
                    'subject_type' => $entry->subject_type,
                    'subject_id' => $entry->subject_id,
                    'context' => $entry->context,
                    'created_at' => $entry->created_at,
                ];

                if ($entry->prev_hash !== $previous || ! hash_equals($entry->hash, self::hashOf($row))) {
                    $broken = $entry->id;

                    return false;
                }

                $previous = $entry->hash;
            }

            return true;
        });

        return $broken;
    }

    /** @param array<mixed> $value */
    private static function sortKeys(array $value): array
    {
        if (! array_is_list($value)) {
            ksort($value);
        }

        return array_map(fn ($v) => is_array($v) ? self::sortKeys($v) : $v, $value);
    }
}
