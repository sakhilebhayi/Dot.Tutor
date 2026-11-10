<?php

namespace App\Domain\Safety\Enums;

use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * The three age bands every feature asks about. A child is under 13, a teen 13 to 17, an adult 18 or over
 * (thresholds in config/safety.php). South African law treats everyone under 18 as a child (Children's Act s1,
 * POPIA s1); the child/teen split is a product distinction for how much a guardian approves, not a legal one.
 */
enum AgeBand: string
{
    case Child = 'child';
    case Teen = 'teen';
    case Adult = 'adult';

    public static function fromBirthDate(CarbonInterface $birthDate, ?CarbonInterface $on = null): self
    {
        $age = $birthDate->diffInYears($on ?? Carbon::now(), absolute: false);

        return match (true) {
            $age < (int) config('safety.ages.teen_from', 13) => self::Child,
            $age < (int) config('safety.ages.adult_from', 18) => self::Teen,
            default => self::Adult,
        };
    }

    public function isMinor(): bool
    {
        return $this !== self::Adult;
    }

    public function label(): string
    {
        return match ($this) {
            self::Child => 'Under 13',
            self::Teen => '13 to 17',
            self::Adult => '18 or older',
        };
    }
}
