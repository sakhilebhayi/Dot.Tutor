<?php

namespace App\Domain\Student\Support;

use App\Domain\Comms\QuietHours;
use App\Models\User;
use Throwable;

/**
 * Which time zone a person reads times in (R-47 polish, D-2001). One rule for every learner screen and every message:
 * the zone the person set in their notification settings, else their tutor profile's zone, else the platform zone
 * (config tutor.timezone). Comms' QuietHours already decides this for message text; this asks it once per person
 * per request (scoped binding), so a screen and the notification about the same lesson can never disagree.
 */
final class ViewerZones
{
    /** @var array<int, string> */
    private array $zones = [];

    public function __construct(private readonly QuietHours $quiet) {}

    public function for(User $user): string
    {
        if (isset($this->zones[$user->id])) {
            return $this->zones[$user->id];
        }

        try {
            $zone = $this->quiet->timezoneFor($user);
        } catch (Throwable) {
            $zone = Times::platformZone();
        }

        return $this->zones[$user->id] = $zone;
    }

    public function forget(User $user): void
    {
        unset($this->zones[$user->id]);
    }
}
