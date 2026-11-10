<?php

namespace Tests\Support;

use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/**
 * An in-memory Paystack for tests: initialise, verify, refunds, transfer recipients, transfers. Each endpoint's
 * answer can be overridden; every call is counted.
 */
class FakePaystack
{
    /** @var array<string, array{amount: int, currency: string}> */
    public array $transactions = [];

    /** @var array<string, array<string, mixed>> verify answers by reference (merged over the default success) */
    public array $verifyOverrides = [];

    /** @var array<string, int> */
    public array $calls = [];

    public string $refundStatus = 'processed';

    /** @var array<string, string> refund id => status for GET /refund/{id} */
    public array $refundFetchStatus = [];

    /** @var array<string, array<string, mixed>> */
    public array $refunds = [];

    public string $transferStatus = 'success';

    public int $fees = 150;

    public bool $down = false;

    public function handle(Request $request): PromiseInterface
    {
        $path = parse_url($request->url(), PHP_URL_PATH);
        $method = $request->method();

        if (! str_starts_with($request->url(), 'https://api.paystack.co/')) {
            return Http::response(['status' => false], 599);
        }

        if ($this->down) {
            return Http::response(['status' => false, 'message' => 'down'], 503);
        }

        return match (true) {
            $method === 'POST' && $path === '/transaction/initialize' => $this->initialize($request),
            $method === 'GET' && str_starts_with($path, '/transaction/verify/') => $this->verify(rawurldecode(substr($path, strlen('/transaction/verify/')))),
            $method === 'POST' && $path === '/refund' => $this->createRefund($request),
            $method === 'GET' && str_starts_with($path, '/refund/') => $this->fetchRefund(substr($path, strlen('/refund/'))),
            $method === 'POST' && $path === '/transferrecipient' => $this->count('recipient', ['status' => true, 'data' => ['recipient_code' => 'RCP_test123']]),
            $method === 'POST' && $path === '/transfer' => $this->initiateTransfer($request),
            $method === 'GET' && str_starts_with($path, '/transfer/verify/') => $this->verifyTransfer(rawurldecode(substr($path, strlen('/transfer/verify/')))),
            default => Http::response(['status' => false, 'message' => 'not faked'], 404),
        };
    }

    public function lastReference(): ?string
    {
        return array_key_last($this->transactions);
    }

    public function count(string $endpoint, array $body, int $status = 200): PromiseInterface
    {
        $this->calls[$endpoint] = ($this->calls[$endpoint] ?? 0) + 1;

        return Http::response($body, $status);
    }

    public function calls(string $endpoint): int
    {
        return $this->calls[$endpoint] ?? 0;
    }

    private function initialize(Request $request): PromiseInterface
    {
        $reference = (string) $request['reference'];
        $this->transactions[$reference] = ['amount' => (int) $request['amount'], 'currency' => (string) $request['currency']];

        return $this->count('initialize', ['status' => true, 'data' => [
            'authorization_url' => 'https://checkout.paystack.com/'.$reference,
            'access_code' => 'ac_'.$reference,
            'reference' => $reference,
        ]]);
    }

    private function verify(string $reference): PromiseInterface
    {
        $known = $this->transactions[$reference] ?? null;

        if ($known === null && ! isset($this->verifyOverrides[$reference])) {
            return $this->count('verify', ['status' => false, 'message' => 'Transaction reference not found'], 400);
        }

        $data = array_merge([
            'id' => 1000 + crc32($reference) % 100000,
            'reference' => $reference,
            'status' => 'success',
            'amount' => $known['amount'] ?? 0,
            'currency' => $known['currency'] ?? 'ZAR',
            'channel' => 'card',
            'fees' => $this->fees,
            'gateway_response' => 'Approved',
            'customer' => ['email' => 'someone@example.com'],
        ], $this->verifyOverrides[$reference] ?? []);

        return $this->count('verify', ['status' => true, 'data' => $data]);
    }

    private function createRefund(Request $request): PromiseInterface
    {
        $id = (string) (9000 + count($this->refunds) + 1);
        $this->refunds[$id] = ['transaction' => $request['transaction'], 'amount' => (int) $request['amount']];

        return $this->count('refund', ['status' => true, 'data' => [
            'id' => (int) $id, 'amount' => (int) $request['amount'], 'currency' => 'ZAR', 'status' => $this->refundStatus,
            'transaction' => ['reference' => $request['transaction']],
        ]]);
    }

    private function fetchRefund(string $id): PromiseInterface
    {
        $refund = $this->refunds[$id] ?? null;

        if ($refund === null) {
            return $this->count('refund_fetch', ['status' => false], 404);
        }

        return $this->count('refund_fetch', ['status' => true, 'data' => [
            'id' => (int) $id, 'amount' => $refund['amount'], 'currency' => 'ZAR', 'status' => $this->refundFetchStatus[$id] ?? 'processed',
            'transaction' => ['reference' => $refund['transaction']],
        ]]);
    }

    private function verifyTransfer(string $reference): PromiseInterface
    {
        return $this->count('transfer_verify', ['status' => true, 'data' => ['reference' => $reference, 'status' => $this->transferStatus, 'amount' => $this->transfers[$reference] ?? 0, 'transfer_code' => 'TRF_test']]);
    }

    /** @var array<string, int> */
    public array $transfers = [];

    private function initiateTransfer(Request $request): PromiseInterface
    {
        $this->transfers[(string) $request['reference']] = (int) $request['amount'];

        return $this->count('transfer', ['status' => true, 'data' => ['reference' => $request['reference'], 'status' => 'pending', 'amount' => $request['amount'], 'transfer_code' => 'TRF_test']]);
    }
}
