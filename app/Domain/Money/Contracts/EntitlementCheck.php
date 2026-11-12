<?php

namespace App\Domain\Money\Contracts;

/**
 * Does a person own access to an item? Learning and community ask this before serving paid content; the
 * check is server side and reads money_entitlements (paid, free, gifted, bundle).
 */
interface EntitlementCheck
{
    public function owns(int $userId, string $type, int $id): bool;

    /** paid | free | gifted | bundle, or null when the person has no access. */
    public function source(int $userId, string $type, int $id): ?string;
}
