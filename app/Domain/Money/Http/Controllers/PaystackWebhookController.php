<?php

namespace App\Domain\Money\Http\Controllers;

use App\Domain\Money\Paystack\PaystackSignature;
use App\Domain\Money\Webhooks\PaystackWebhookInbox;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * POST /api/tutor/money/webhooks/paystack. The signature is checked on the raw body (constant time) before
 * anything is parsed; the event is stored once and processed by a queued job that asks Paystack again. The
 * body, the signature and the key are never logged.
 */
class PaystackWebhookController extends Controller
{
    private const MAX_BYTES = 1_000_000;

    public function __invoke(Request $request, PaystackSignature $signature, PaystackWebhookInbox $inbox): JsonResponse
    {
        $raw = $request->getContent();

        if (strlen($raw) > self::MAX_BYTES) {
            return response()->json(['error' => 'too_large', 'message' => 'Body too large.'], 413);
        }

        if (! $signature->isValid($raw, $request->header('x-paystack-signature'))) {
            Log::warning('paystack.webhook_signature_refused', ['bytes' => strlen($raw)]);

            return response()->json(['error' => 'invalid_signature', 'message' => 'Signature check failed.'], 401);
        }

        $payload = json_decode($raw, true);

        if (! is_array($payload)) {
            return response()->json(['error' => 'invalid_body', 'message' => 'Body is not JSON.'], 400);
        }

        return response()->json(['status' => $inbox->receive($payload)]);
    }
}
