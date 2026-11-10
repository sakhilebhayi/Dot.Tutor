<?php

namespace App\Domain\Money\Paystack;

use App\Domain\Money\Exceptions\PaymentGatewayException;

/**
 * A Paystack transaction as reported by the verify endpoint (the same `data` shape as charge.success).
 * Amounts are in the currency's subunit (ZAR cents), which is what this app stores. Customer and card
 * details are never read.
 */
final readonly class PaystackTransaction
{
    public function __construct(
        public string $id,
        public string $reference,
        public string $status,
        public int $amountMinor,
        public string $currency,
        public ?string $channel = null,
        public ?string $paidAt = null,
        public ?string $gatewayResponse = null,
        public ?int $feesMinor = null,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     *
     * @throws PaymentGatewayException when the facts we compare against are missing or malformed
     */
    public static function fromData(array $data): self
    {
        $amount = $data['amount'] ?? null;
        $reference = $data['reference'] ?? null;
        $status = $data['status'] ?? null;
        $currency = $data['currency'] ?? null;

        if (! self::isWhole($amount) || ! is_string($reference) || $reference === '' || ! is_string($status) || ! is_string($currency) || $currency === '') {
            throw PaymentGatewayException::because('invalid_response', 'Paystack sent an answer we could not read.');
        }

        $id = $data['id'] ?? null;
        $fees = $data['fees'] ?? null;

        return new self(
            id: is_int($id) || (is_string($id) && $id !== '') ? (string) $id : $reference,
            reference: $reference,
            status: strtolower($status),
            amountMinor: (int) $amount,
            currency: strtoupper($currency),
            channel: is_string($data['channel'] ?? null) ? mb_substr($data['channel'], 0, 30) : null,
            paidAt: is_string($data['paid_at'] ?? null) ? $data['paid_at'] : null,
            gatewayResponse: is_string($data['gateway_response'] ?? null) ? mb_substr($data['gateway_response'], 0, 200) : null,
            feesMinor: self::isWhole($fees) ? (int) $fees : null,
        );
    }

    private static function isWhole(mixed $value): bool
    {
        return (is_int($value) && $value >= 0) || (is_string($value) && ctype_digit($value));
    }

    public function isSuccessful(): bool
    {
        return $this->status === 'success';
    }
}
