<?php

namespace App\Domain\Money\Paystack;

use App\Domain\Money\Exceptions\PaymentGatewayException;

/**
 * Paystack signs each webhook body with HMAC-SHA512 of the raw body using the secret key, in the
 * x-paystack-signature header. Compared in constant time; with no usable key every webhook is refused.
 */
class PaystackSignature
{
    public function __construct(private PaystackConfig $config) {}

    public function isValid(string $rawBody, ?string $signature): bool
    {
        try {
            $secret = $this->config->secretKey();
        } catch (PaymentGatewayException) {
            return false;
        }

        $signature = strtolower(trim((string) $signature));

        return $signature !== '' && hash_equals(hash_hmac('sha512', $rawBody, $secret), $signature);
    }
}
