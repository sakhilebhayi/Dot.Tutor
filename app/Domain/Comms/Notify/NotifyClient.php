<?php

namespace App\Domain\Comms\Notify;

use App\Models\Team;
use App\Models\User;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Tells Dot.Notify "this happened to this company, tell this person" (POST /api/ingest/events), adapted from the
 * Dot.Ehail client. Off unless NOTIFY_INGEST_URL and NOTIFY_INGEST_SECRET are both set.
 *
 * Signing is Notify's scheme: HMAC-SHA256 over "{timestamp}.{raw body}" with this platform's secret, sent as
 * "sha256=<hex>" in X-InfoDot-Signature with the unix time in X-InfoDot-Timestamp, made at send time so a retry
 * carries a fresh timestamp. Notify routes by company: the recipient's current team's infodot_team_uuid, or their
 * personal team's. Calls are made by the delivery worker (cron), never inside a person's request; retries are the
 * delivery row's. Logs carry the event name and status only: never an email address, a body or a secret.
 */
class NotifyClient
{
    public const DONE = 'done';

    public const RETRY = 'retry';

    public const GIVE_UP = 'give_up';

    private const FAILURES_KEY = 'comms.notify.circuit.failures';

    private const OPEN_UNTIL_KEY = 'comms.notify.circuit.open_until';

    public function enabled(): bool
    {
        return filled(config('notify.url')) && filled(config('notify.secret'));
    }

    public function eventFor(string $type): ?string
    {
        $event = config('notify.events.'.$type);

        return is_string($event) && $event !== '' ? $event : null;
    }

    /**
     * @param  array<string, scalar|null>  $data
     * @return array<string, mixed>|null null when the person cannot be addressed (no company, no email)
     */
    public function payload(User $recipient, string $event, string $idempotency, array $data): ?array
    {
        $uuid = $this->companyUuid($recipient);
        $email = trim((string) $recipient->email);

        if (! filled($uuid) || $email === '') {
            return null;
        }

        $key = $event.':'.sha1($email).':'.$idempotency;

        return [
            'platform' => (string) config('notify.platform', 'tutor'),
            'company_uuid' => $uuid,
            'event' => $event,
            'recipient' => ['email' => $email],
            'data' => $data,
            'idempotency_key' => strlen($key) <= 120 ? $key : substr($event, 0, 40).':'.sha1($key),
            'notification_type' => 'transactional',
        ];
    }

    /** Read only: resolving a company must not switch the user's current team. */
    public function companyUuid(User $user): ?string
    {
        $current = $user->current_team_id ? Team::query()->find($user->current_team_id) : null;

        if ($current !== null && filled($current->infodot_team_uuid)) {
            return $current->infodot_team_uuid;
        }

        $personal = Team::query()->where('user_id', $user->getKey())->where('personal_team', true)->first();

        return filled($personal?->infodot_team_uuid) ? $personal->infodot_team_uuid : null;
    }

    /**
     * POST one event now. Never throws.
     *
     * @param  array<string, mixed>  $payload
     * @return array{outcome: string, http: ?int, notify_status: ?string}
     */
    public function deliver(array $payload): array
    {
        if (! $this->enabled()) {
            return ['outcome' => self::GIVE_UP, 'http' => null, 'notify_status' => 'notify_not_configured'];
        }

        if ($this->pausedFor() > 0) {
            return ['outcome' => self::RETRY, 'http' => null, 'notify_status' => 'circuit_open'];
        }

        try {
            $body = (string) json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $timestamp = (string) now()->timestamp;
            $timeout = (int) config('notify.timeout', 5);

            $response = Http::timeout($timeout)->connectTimeout($timeout)->acceptJson()
                ->withHeaders([
                    'X-InfoDot-Timestamp' => $timestamp,
                    'X-InfoDot-Signature' => 'sha256='.hash_hmac('sha256', $timestamp.'.'.$body, (string) config('notify.secret')),
                ])
                ->withBody($body, 'application/json')
                ->post((string) config('notify.url'));
        } catch (ConnectionException) {
            $this->recordFailure();
            Log::warning('Dot.Notify unreachable; will retry.', ['event' => $payload['event'] ?? null]);

            return ['outcome' => self::RETRY, 'http' => null, 'notify_status' => 'unreachable'];
        } catch (Throwable $e) {
            $this->recordFailure();
            Log::warning('Dot.Notify call failed; will retry.', ['event' => $payload['event'] ?? null, 'error' => class_basename($e)]);

            return ['outcome' => self::RETRY, 'http' => null, 'notify_status' => 'error'];
        }

        $status = $response->status();
        $notifyStatus = is_string($response->json('status')) ? (string) $response->json('status') : null;

        if ($status >= 200 && $status < 300) {
            $this->recordSuccess();

            return ['outcome' => self::DONE, 'http' => $status, 'notify_status' => $notifyStatus];
        }

        if ($status === 429 || $status >= 500) {
            $this->recordFailure();

            return ['outcome' => self::RETRY, 'http' => $status, 'notify_status' => 'http_'.$status];
        }

        // 401: our secret is wrong; 404: the company is unknown to Notify; 422: payload refused. Retrying cannot fix these.
        $this->recordSuccess();
        Log::error('Dot.Notify refused the event.', ['event' => $payload['event'] ?? null, 'status' => $status]);

        return ['outcome' => self::GIVE_UP, 'http' => $status, 'notify_status' => 'http_'.$status];
    }

    public function pausedFor(): int
    {
        try {
            return max(0, (int) Cache::get(self::OPEN_UNTIL_KEY, 0) - now()->timestamp);
        } catch (Throwable) {
            return 0;
        }
    }

    private function recordFailure(): void
    {
        try {
            Cache::add(self::FAILURES_KEY, 0, 3600);
            if ((int) Cache::increment(self::FAILURES_KEY) >= 5) {
                Cache::put(self::OPEN_UNTIL_KEY, now()->timestamp + 60, 120);
            }
        } catch (Throwable) {
        }
    }

    private function recordSuccess(): void
    {
        try {
            Cache::forget(self::FAILURES_KEY);
            Cache::forget(self::OPEN_UNTIL_KEY);
        } catch (Throwable) {
        }
    }
}
