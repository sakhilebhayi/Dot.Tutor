<?php

namespace App\Domain\Perf;

use App\Domain\Marketplace\Enums\SeatStatus;
use App\Domain\Money\Ledger\Ledger;
use App\Enums\SessionStatus;
use Illuminate\Support\Facades\DB;

/**
 * What must stay true however many people act at once (R-47 D-1609, docs/R47_PERF.md section 5). Read-only; used by
 * the concurrency tests after every race and by `php scripts/perf/world.php invariants` after a load run.
 *
 *  - every ledger journal balances (debits equal credits);
 *  - no tutor holds two one-to-one lessons at overlapping times (requests, holds, confirmed, running or held);
 *  - no group lesson has more held seats than its capacity;
 *  - no one is enrolled twice in a course;
 *  - no two orders share an idempotency key, no two marketplace requests share one per person and scope;
 *  - no booking, seat or package is paid by two orders;
 *  - no order line has two sale earnings; no order has two successful payments.
 */
class TutorInvariants
{
    /** @return array{ok: bool, breaches: array<string, int>, samples: array<string, mixed>} */
    public static function check(): array
    {
        $breaches = [];
        $samples = [];
        $note = function (string $name, $rows) use (&$breaches, &$samples): void {
            $rows = collect($rows);
            $breaches[$name] = $rows->count();
            if ($rows->isNotEmpty()) {
                $samples[$name] = $rows->take(5)->values()->all();
            }
        };

        $note('unbalanced_journals', app(Ledger::class)->unbalancedJournals());
        $note('double_booked_tutor_time', self::overlappingLessons());

        $holding = SeatStatus::holding();
        $note('group_over_capacity', DB::table('tutor_sessions')
            ->where('kind', 'group')
            ->whereNotNull('capacity')
            ->whereRaw('(select count(*) from tutor_session_participants p where p.tutor_session_id = tutor_sessions.id and p.status in ('.implode(',', array_fill(0, count($holding), '?')).')) > tutor_sessions.capacity', array_map(fn ($s) => $s instanceof \BackedEnum ? $s->value : $s, $holding))
            ->pluck('id'));
        $note('duplicate_enrolments', DB::table('learning_enrolments')->select('course_id', 'user_id')->groupBy('course_id', 'user_id')->havingRaw('count(*) > 1')->get());
        $note('duplicate_order_keys', DB::table('money_orders')->whereNotNull('idempotency_key')->select('idempotency_key')->groupBy('idempotency_key')->havingRaw('count(*) > 1')->pluck('idempotency_key'));
        $note('duplicate_request_keys', DB::table('marketplace_idempotency_keys')->select('user_id', 'scope', 'idempotency_key')->groupBy('user_id', 'scope', 'idempotency_key')->havingRaw('count(*) > 1')->get());
        $note('paid_twice', DB::table('money_order_lines')
            ->join('money_orders', 'money_orders.id', '=', 'money_order_lines.order_id')
            ->whereIn('money_orders.status', ['paid', 'partially_refunded'])
            ->whereIn('money_order_lines.purchasable_type', ['booking', 'seat', 'package'])
            ->select('money_order_lines.purchasable_type', 'money_order_lines.purchasable_id')
            ->groupBy('money_order_lines.purchasable_type', 'money_order_lines.purchasable_id')
            ->havingRaw('count(*) > 1')->get());
        $note('duplicate_sale_earnings', DB::table('money_earnings')->where('kind', 'sale')->whereNotNull('order_line_id')
            ->select('order_line_id')->groupBy('order_line_id')->havingRaw('count(*) > 1')->pluck('order_line_id'));
        $note('order_paid_twice', DB::table('money_payments')->where('status', 'paid')
            ->select('order_id')->groupBy('order_id')->havingRaw('count(*) > 1')->pluck('order_id'));

        return ['ok' => array_sum($breaches) === 0, 'breaches' => $breaches, 'samples' => $samples];
    }

    /**
     * One-to-one lessons of the same tutor that overlap in time while both hold it. Read in start order per tutor and
     * compared with the latest end seen so far (engine-neutral: no date arithmetic in SQL).
     *
     * @return list<array{tutor_profile_id: int, a: int, b: int}>
     */
    private static function overlappingLessons(): array
    {
        $statuses = array_values(array_unique([...SessionStatus::holdingTime(), SessionStatus::Completed->value]));
        $found = [];
        $tutor = null;
        $lastEnd = 0;
        $lastId = null;

        DB::table('tutor_sessions')->where('kind', '!=', 'group')->whereIn('status', $statuses)
            ->orderBy('tutor_profile_id')->orderBy('starts_at')->orderBy('id')
            ->select('id', 'tutor_profile_id', 'starts_at', 'duration_minutes')
            ->cursor()->each(function ($row) use (&$found, &$tutor, &$lastEnd, &$lastId) {
                $start = (int) strtotime($row->starts_at.(preg_match('/[zZ]|[+-]\d\d:?\d\d$/', (string) $row->starts_at) ? '' : ' UTC'));
                $end = $start + (int) $row->duration_minutes * 60;
                if ($row->tutor_profile_id !== $tutor) {
                    [$tutor, $lastEnd, $lastId] = [$row->tutor_profile_id, $end, $row->id];

                    return;
                }
                if ($start < $lastEnd) {
                    $found[] = ['tutor_profile_id' => (int) $tutor, 'a' => (int) $lastId, 'b' => (int) $row->id];
                }
                if ($end > $lastEnd) {
                    [$lastEnd, $lastId] = [$end, $row->id];
                }
            });

        return $found;
    }
}
