<?php

namespace App\Domain\Money;

use App\Domain\Money\Contracts\Purchasable;
use Closure;

/**
 * The registry that turns (type, id) into a Purchasable. Each owning domain registers its types in its own
 * service provider (learning: course, resource; marketplace: offering, package; community: room). Money
 * registers booking (an adapter over the existing tutor_sessions) and bundle.
 */
class Purchasables
{
    /** Types an order line may hold. */
    public const TYPES = ['booking', 'offering', 'package', 'course', 'resource', 'room', 'bundle'];

    /** @var array<string, Closure(int): ?Purchasable> */
    private array $resolvers = [];

    /**
     * @param  Closure(int): ?Purchasable  $resolver
     */
    public function register(string $type, Closure $resolver): void
    {
        $this->resolvers[$type] = $resolver;
    }

    /**
     * @param  Closure(int): ?Purchasable  $resolver
     */
    public function registerIfMissing(string $type, Closure $resolver): void
    {
        $this->resolvers[$type] ??= $resolver;
    }

    public function has(string $type): bool
    {
        return isset($this->resolvers[$type]);
    }

    public function resolve(string $type, int $id): ?Purchasable
    {
        $resolver = $this->resolvers[$type] ?? null;

        return $resolver === null ? null : $resolver($id);
    }

    /** @return list<string> */
    public function registeredTypes(): array
    {
        return array_keys($this->resolvers);
    }
}
