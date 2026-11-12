<?php

namespace App\Domain\Money\Ledger;

use InvalidArgumentException;

/**
 * The chart of accounts. An account is a type code, optionally with a party id after a colon
 * (tutor_payable:12 is what the platform owes tutor user 12). docs/R47_MONEY.md explains each one.
 */
final class Account
{
    public const GATEWAY_CLEARING = 'gateway_clearing';

    public const PLATFORM_BANK = 'platform_bank';

    public const GATEWAY_FEES = 'gateway_fees';

    public const TUTOR_PENDING = 'tutor_pending';

    public const TUTOR_PAYABLE = 'tutor_payable';

    public const PLATFORM_COMMISSION = 'platform_commission';

    public const PLATFORM_FEES = 'platform_fees';

    public const PLATFORM_SALES = 'platform_sales';

    public const LEARNER_CREDIT = 'learner_credit';

    public const PROMOTIONS_EXPENSE = 'promotions_expense';

    public const REFUNDS = 'refunds';

    public const DISPUTE_LOSSES = 'dispute_losses';

    /** @var array<string, array{label: string, nature: 'asset'|'liability'|'revenue'|'expense', party: ?string}> */
    public const CHART = [
        self::GATEWAY_CLEARING => ['label' => 'Held at the payment provider (Paystack)', 'nature' => 'asset', 'party' => null],
        self::PLATFORM_BANK => ['label' => 'Platform bank account', 'nature' => 'asset', 'party' => null],
        self::TUTOR_PENDING => ['label' => 'Tutor earnings, pending', 'nature' => 'liability', 'party' => 'tutor'],
        self::TUTOR_PAYABLE => ['label' => 'Tutor earnings, available to pay out', 'nature' => 'liability', 'party' => 'tutor'],
        self::LEARNER_CREDIT => ['label' => 'Promotional credit held by a learner', 'nature' => 'liability', 'party' => 'user'],
        self::PLATFORM_COMMISSION => ['label' => 'Commission revenue', 'nature' => 'revenue', 'party' => null],
        self::PLATFORM_FEES => ['label' => 'Other charges to tutors', 'nature' => 'revenue', 'party' => null],
        self::PLATFORM_SALES => ['label' => 'Sales of platform-owned items', 'nature' => 'revenue', 'party' => null],
        self::GATEWAY_FEES => ['label' => 'Payment provider fees', 'nature' => 'expense', 'party' => null],
        self::PROMOTIONS_EXPENSE => ['label' => 'Promotions funded by the platform (coupons, credits)', 'nature' => 'expense', 'party' => null],
        self::REFUNDS => ['label' => 'Platform revenue returned in refunds', 'nature' => 'expense', 'party' => null],
        self::DISPUTE_LOSSES => ['label' => 'Card disputes lost', 'nature' => 'expense', 'party' => null],
    ];

    public static function of(string $type, ?int $partyId = null): string
    {
        if (! isset(self::CHART[$type])) {
            throw new InvalidArgumentException("Unknown ledger account type [{$type}].");
        }

        $needsParty = self::CHART[$type]['party'] !== null;

        if ($needsParty !== ($partyId !== null)) {
            throw new InvalidArgumentException("Account [{$type}] ".($needsParty ? 'needs' : 'takes no').' party id.');
        }

        return $partyId === null ? $type : $type.':'.$partyId;
    }

    public static function tutorPending(int $tutorId): string
    {
        return self::of(self::TUTOR_PENDING, $tutorId);
    }

    public static function tutorPayable(int $tutorId): string
    {
        return self::of(self::TUTOR_PAYABLE, $tutorId);
    }

    public static function learnerCredit(int $userId): string
    {
        return self::of(self::LEARNER_CREDIT, $userId);
    }

    /**
     * @return array{0: string, 1: ?int}
     */
    public static function parse(string $account): array
    {
        [$type, $party] = array_pad(explode(':', $account, 2), 2, null);

        if (! isset(self::CHART[$type])) {
            throw new InvalidArgumentException("Unknown ledger account [{$account}].");
        }

        if ((self::CHART[$type]['party'] !== null) !== ($party !== null) || ($party !== null && ! ctype_digit($party))) {
            throw new InvalidArgumentException("Ledger account [{$account}] has a missing or unexpected party id.");
        }

        return [$type, $party === null ? null : (int) $party];
    }

    public static function isDebitNormal(string $type): bool
    {
        return in_array(self::CHART[$type]['nature'] ?? null, ['asset', 'expense'], true);
    }

    public static function label(string $account): string
    {
        [$type, $party] = self::parse($account);

        return self::CHART[$type]['label'].($party !== null ? ' #'.$party : '');
    }
}
