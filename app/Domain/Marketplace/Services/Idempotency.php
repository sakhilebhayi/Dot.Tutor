<?php

namespace App\Domain\Marketplace\Services;

use App\Domain\Marketplace\Exceptions\MarketplaceException;
use App\Domain\Marketplace\Models\IdempotencyKey;
use App\Models\User;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Idempotency-Key handling for every POST that creates a booking, seat,
 * series or package (architecture section 2). The key row is inserted in the
 * same transaction as the resource, under a unique (user, scope, key) index:
 *  - a retry with the same key and body gets the original resource back
 *    (replayed = true) and creates nothing;
 *  - the same key with a different body is refused (422);
 *  - if creating the resource fails, the key row rolls back with it, so the
 *    client may retry with the same key.
 */
class Idempotency
{
    /**
     * @template T of Model
     *
     * @param  array<string, mixed>  $payload
     * @param  Closure(): T  $create
     * @param  Closure(int): (T|null)  $find
     * @return array{0: T, 1: bool} [resource, replayed]
     */
    public function run(User $user, string $scope, ?string $key, array $payload, Closure $create, Closure $find): array
    {
        if ($key === null || $key === '') {
            return [$create(), false];
        }
        if (strlen($key) > 120 || ! preg_match('/^[A-Za-z0-9._:\-]+$/', $key)) {
            throw new MarketplaceException('invalid_idempotency_key', 'Idempotency-Key must be 1 to 120 letters, digits, dots, dashes, colons or underscores.', 422);
        }

        $hash = $this->hash($payload);

        $existing = $this->lookup($user, $scope, $key);
        if ($existing !== null) {
            return [$this->replay($existing, $hash, $find), true];
        }

        try {
            return DB::transaction(function () use ($user, $scope, $key, $hash, $create) {
                $record = IdempotencyKey::create([
                    'user_id' => $user->id,
                    'scope' => $scope,
                    'idempotency_key' => $key,
                    'request_hash' => $hash,
                ]);
                $resource = $create();
                $record->update(['resource_type' => class_basename($resource), 'resource_id' => $resource->getKey()]);

                return [$resource, false];
            });
        } catch (UniqueConstraintViolationException $e) {
            // Another request with the same key committed first.
            $existing = $this->lookup($user, $scope, $key);
            if ($existing === null) {
                throw $e;
            }

            return [$this->replay($existing, $hash, $find), true];
        }
    }

    private function lookup(User $user, string $scope, string $key): ?IdempotencyKey
    {
        return IdempotencyKey::query()
            ->where('user_id', $user->id)
            ->where('scope', $scope)
            ->where('idempotency_key', $key)
            ->first();
    }

    /**
     * @param  Closure(int): (Model|null)  $find
     */
    private function replay(IdempotencyKey $record, string $hash, Closure $find): Model
    {
        if (! hash_equals($record->request_hash, $hash)) {
            throw new MarketplaceException('idempotency_mismatch', 'This Idempotency-Key was already used for a different request. Use a new key.', 422);
        }

        $resource = $record->resource_id !== null ? $find($record->resource_id) : null;
        if ($resource === null) {
            throw new MarketplaceException('idempotency_unavailable', 'The original request for this key is no longer available. Use a new key.', 409);
        }

        return $resource;
    }

    /** @param  array<string, mixed>  $payload */
    private function hash(array $payload): string
    {
        $normalise = function ($value) use (&$normalise) {
            if (is_array($value)) {
                if (! array_is_list($value)) {
                    ksort($value);
                }

                return array_map($normalise, $value);
            }

            return is_scalar($value) || $value === null ? (string) $value : json_encode($value);
        };

        return hash('sha256', (string) json_encode($normalise($payload)));
    }
}
