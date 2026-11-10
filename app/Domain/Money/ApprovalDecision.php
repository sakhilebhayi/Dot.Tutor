<?php

namespace App\Domain\Money;

/**
 * What PurchaseApprovals said about an order.
 */
final readonly class ApprovalDecision
{
    public const ALLOWED = 'allowed';

    public const HELD = 'held';

    public const REFUSED = 'refused';

    public function __construct(
        public string $outcome,
        public ?string $message = null,
        public ?string $reference = null,
        public ?string $guardianStatus = null,
    ) {}

    public static function allowed(string $guardianStatus = 'not_needed'): self
    {
        return new self(self::ALLOWED, guardianStatus: $guardianStatus);
    }

    public static function held(string $message, ?string $reference = null): self
    {
        return new self(self::HELD, $message, $reference, 'pending');
    }

    public static function refused(string $message): self
    {
        return new self(self::REFUSED, $message, guardianStatus: 'refused');
    }

    public function isAllowed(): bool
    {
        return $this->outcome === self::ALLOWED;
    }
}
