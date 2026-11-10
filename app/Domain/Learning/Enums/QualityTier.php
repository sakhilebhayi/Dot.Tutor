<?php

namespace App\Domain\Learning\Enums;

/** Quality label of a library resource. Reviewed and OfficiallySourced exist only after a recorded review. */
enum QualityTier: string
{
    case Community = 'community';
    case TutorCreated = 'tutor_created';
    case Reviewed = 'reviewed';
    case OfficiallySourced = 'officially_sourced';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $case) => $case->value, self::cases());
    }

    public function label(): string
    {
        return match ($this) {
            self::Community => 'Community contributed',
            self::TutorCreated => 'Tutor created',
            self::Reviewed => 'Reviewed',
            self::OfficiallySourced => 'Officially sourced',
        };
    }
}
