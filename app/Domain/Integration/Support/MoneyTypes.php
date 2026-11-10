<?php

namespace App\Domain\Integration\Support;

use App\Domain\Marketplace\Models\TutorPackagePurchase;
use Illuminate\Database\Eloquent\Model;

/**
 * Names for the same thing on both sides of the money seam. Money order lines use short purchasable types; the
 * marketplace's PaymentService and the learning domain use their own.
 */
final class MoneyTypes
{
    /** marketplace PaymentService type => money purchasable type */
    public const MARKETPLACE_TO_MONEY = [
        'tutor_session' => 'booking',
        'tutor_session_participant' => 'seat',
        'tutor_package_purchase' => 'package',
    ];

    /** learning Sellable type => money purchasable type (money has its own "bundle" of mixed items) */
    public const LEARNING_TO_MONEY = [
        'course' => 'course',
        'resource' => 'resource',
        'bundle' => 'course_bundle',
    ];

    public static function marketplaceType(string $moneyType): ?string
    {
        $flipped = array_flip(self::MARKETPLACE_TO_MONEY);

        return $flipped[$moneyType] ?? null;
    }

    public static function learningToMoney(string $sellableType): string
    {
        return self::LEARNING_TO_MONEY[$sellableType] ?? $sellableType;
    }

    /** A subject a guardian may already have approved as a purchase (marketplace packages). */
    public static function guardianSubject(string $moneyType, int $id): ?Model
    {
        return match ($moneyType) {
            'package' => TutorPackagePurchase::query()->find($id),
            default => null,
        };
    }
}
