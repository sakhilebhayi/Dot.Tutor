<?php

namespace App\Domain\Money\Services;

use App\Domain\Money\Contracts\MoneyAudit;
use App\Domain\Money\Exceptions\CheckoutRefused;
use App\Domain\Money\Ledger\Ledger;
use App\Domain\Money\Ledger\PostingRules;
use App\Domain\Money\Models\Dispute;
use App\Domain\Money\Models\Payment;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Card disputes (chargebacks). Recorded from Paystack's charge.dispute.* webhooks or entered by finance; the
 * outcome is set by finance (the webhook's resolution wording is not trusted to move money). A lost dispute
 * posts the loss to the ledger once.
 */
class Disputes
{
    public function __construct(private Ledger $ledger, private PostingRules $rules, private FinanceIssues $issues, private MoneyAudit $audit) {}

    /**
     * @param  array<string, mixed>  $data  a charge.dispute.* `data` object (personal data already stripped)
     */
    public function recordFromWebhook(string $event, array $data): string
    {
        $id = $data['id'] ?? null;
        $transaction = is_array($data['transaction'] ?? null) ? $data['transaction'] : [];
        $reference = is_string($transaction['reference'] ?? null) ? $transaction['reference'] : null;

        if (! is_scalar($id) || (string) $id === '') {
            return 'dispute:unreadable';
        }

        $payment = $reference === null ? null : Payment::where('reference', $reference)->first();
        $amount = $transaction['amount'] ?? $data['refund_amount'] ?? null;

        $dispute = Dispute::firstOrNew(['provider_dispute_id' => (string) $id]);
        $dispute->fill([
            'payment_id' => $payment?->id,
            'order_id' => $payment?->order_id,
            'reference' => $reference === null ? null : mb_substr($reference, 0, 100),
            'amount_minor' => is_numeric($amount) ? (int) $amount : $dispute->amount_minor,
            'currency' => $payment?->currency ?? (is_string($data['currency'] ?? null) ? strtoupper($data['currency']) : null),
            'status' => $dispute->exists ? $dispute->status : 'open',
            'reason' => is_string($data['category'] ?? null) ? mb_substr($data['category'], 0, 255) : $dispute->reason,
        ]);

        if ($event === 'charge.dispute.resolve' && is_string($data['resolution'] ?? null)) {
            $dispute->notes = trim(($dispute->notes ? $dispute->notes."\n" : '').'Paystack resolution: '.mb_substr($data['resolution'], 0, 80));
        }

        $dispute->save();

        $this->issues->raise('dispute', "dispute:{$dispute->id}:{$event}", "Card dispute on {$reference} ({$event}): record the outcome", [
            'severity' => 'critical', 'subject_type' => 'dispute', 'subject_id' => $dispute->id,
            'amount_minor' => $dispute->amount_minor, 'currency' => $dispute->currency,
        ]);

        return 'dispute:'.$dispute->status;
    }

    /**
     * @throws CheckoutRefused
     */
    public function setOutcome(Dispute $dispute, string $status, ?string $note, User $actor): Dispute
    {
        if (! in_array($status, Dispute::STATUSES, true)) {
            throw CheckoutRefused::because('invalid_status', 'Choose open, won, lost or resolved.');
        }

        if ($status === 'lost' && ((int) $dispute->amount_minor < 1 || $dispute->currency === null)) {
            throw CheckoutRefused::because('amount_missing', 'Record the disputed amount before marking it lost.');
        }

        DB::transaction(function () use ($dispute, $status, $note, $actor) {
            $dispute->forceFill([
                'status' => $status,
                'notes' => $note === null ? $dispute->notes : trim(($dispute->notes ? $dispute->notes."\n" : '').mb_substr($note, 0, 500)),
                'resolved_at' => $status === 'open' ? null : now(),
                'recorded_by' => $dispute->recorded_by ?? $actor->id,
            ])->save();

            if ($status === 'lost') {
                $this->ledger->post($this->rules->disputeLost($dispute));
            }
        });

        $this->audit->record($actor, 'money.dispute_outcome', $dispute, ['status' => $status]);

        return $dispute->refresh();
    }
}
