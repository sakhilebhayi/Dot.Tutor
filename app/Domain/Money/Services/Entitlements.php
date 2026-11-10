<?php

namespace App\Domain\Money\Services;

use App\Domain\Money\Contracts\EntitlementCheck;
use App\Domain\Money\Models\BundleItem;
use App\Domain\Money\Models\Entitlement;
use App\Domain\Money\Models\OrderLine;
use App\Models\User;

/**
 * Who owns access to what. Granted when an order is paid (paid or free), by bundle, or as a gift from an
 * operator; revoked (kept as history) when a line is fully refunded.
 */
class Entitlements implements EntitlementCheck
{
    public function owns(int $userId, string $type, int $id): bool
    {
        return $this->source($userId, $type, $id) !== null;
    }

    public function source(int $userId, string $type, int $id): ?string
    {
        $sources = Entitlement::where('user_id', $userId)->where('entitled_type', $type)->where('entitled_id', $id)
            ->whereNull('revoked_at')->pluck('source')->all();

        foreach (['paid', 'bundle', 'gifted', 'free'] as $preferred) {
            if (in_array($preferred, $sources, true)) {
                return $preferred;
            }
        }

        return null;
    }

    public function grantForLine(OrderLine $line, int $buyerId): void
    {
        $source = $line->price_minor === 0 ? 'free' : 'paid';
        $this->grant($buyerId, $line->purchasable_type, $line->purchasable_id, $source, $line->id);

        if ($line->purchasable_type === 'bundle') {
            foreach (BundleItem::where('bundle_id', $line->purchasable_id)->get() as $item) {
                $this->grant($buyerId, $item->purchasable_type, $item->purchasable_id, 'bundle', $line->id);
            }
        }
    }

    public function revokeForLine(OrderLine $line, string $reason): void
    {
        Entitlement::where('order_line_id', $line->id)->whereNull('revoked_at')
            ->update(['revoked_at' => now(), 'revoke_reason' => mb_substr($reason, 0, 255), 'updated_at' => now()]);
    }

    public function gift(User $user, string $type, int $id, User $actor): Entitlement
    {
        return Entitlement::firstOrCreate(
            ['user_id' => $user->id, 'entitled_type' => $type, 'entitled_id' => $id, 'source' => 'gifted', 'revoked_at' => null],
            ['granted_by' => $actor->id],
        );
    }

    private function grant(int $userId, string $type, int $id, string $source, int $lineId): void
    {
        Entitlement::firstOrCreate(
            ['user_id' => $userId, 'entitled_type' => $type, 'entitled_id' => $id, 'order_line_id' => $lineId],
            ['source' => $source],
        );
    }
}
