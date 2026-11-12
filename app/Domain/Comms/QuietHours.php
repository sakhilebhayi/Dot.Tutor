<?php

namespace App\Domain\Comms;

use App\Domain\Comms\Models\UserCommsSettings;
use App\Models\TutorProfile;
use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use DateTimeZone;
use InvalidArgumentException;
use Throwable;

/**
 * Quiet hours: push and SMS that would arrive between the person's start and end time (their local wall clock)
 * wait until the end. Email and the in-app inbox are never held (they do not wake anyone). Urgent categories
 * (safeguarding) are never held. Also the one place a person's time zone is decided for message text.
 */
final class QuietHours
{
    /** @return array{enabled: bool, start: string, end: string, timezone: string, timezone_source: string} */
    public function settings(User $user): array
    {
        $row = UserCommsSettings::query()->where('user_id', $user->id)->first();
        $tz = $this->timezone($user, $row);

        return [
            'enabled' => $row?->quiet_enabled ?? (bool) config('comms.quiet_hours.enabled', true),
            'start' => $row?->quiet_start ?? (string) config('comms.quiet_hours.start', '21:00'),
            'end' => $row?->quiet_end ?? (string) config('comms.quiet_hours.end', '07:00'),
            'timezone' => $tz[0],
            'timezone_source' => $tz[1],
        ];
    }

    public function timezoneFor(User $user): string
    {
        return $this->timezone($user, UserCommsSettings::query()->where('user_id', $user->id)->first())[0];
    }

    /**
     * When a push or SMS created now should go out: null = now, otherwise the end of the current quiet period.
     */
    public function deferUntil(User $user, ?CarbonInterface $at = null): ?CarbonImmutable
    {
        $s = $this->settings($user);

        if (! $s['enabled'] || $s['start'] === $s['end']) {
            return null;
        }

        $local = CarbonImmutable::instance($at ?? now())->setTimezone($s['timezone']);
        [$sh, $sm] = array_map('intval', explode(':', $s['start']));
        [$eh, $em] = array_map('intval', explode(':', $s['end']));
        $minute = $local->hour * 60 + $local->minute;
        $start = $sh * 60 + $sm;
        $end = $eh * 60 + $em;

        $inside = $start < $end ? ($minute >= $start && $minute < $end) : ($minute >= $start || $minute < $end);

        if (! $inside) {
            return null;
        }

        $endToday = $local->setTime($eh, $em);
        $until = $endToday->greaterThan($local) ? $endToday : $endToday->addDay()->setTime($eh, $em);

        return $until->utc();
    }

    /**
     * @throws InvalidArgumentException for a bad time or time zone
     */
    public function save(User $user, bool $enabled, string $start, string $end, ?string $timezone): void
    {
        foreach ([$start, $end] as $time) {
            if (! preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $time)) {
                throw new InvalidArgumentException('Use a time like 21:00.');
            }
        }

        if ($timezone !== null && $timezone !== '' && ! in_array($timezone, DateTimeZone::listIdentifiers(), true)) {
            throw new InvalidArgumentException('Choose a time zone from the list.');
        }

        UserCommsSettings::updateOrCreate(['user_id' => $user->id], [
            'quiet_enabled' => $enabled,
            'quiet_start' => $start,
            'quiet_end' => $end,
            'timezone' => $timezone ?: null,
        ]);
    }

    /** @return array{0: string, 1: string} zone and where it came from */
    private function timezone(User $user, ?UserCommsSettings $row): array
    {
        $candidates = [
            [$row?->timezone, 'yours'],
            [TutorProfile::query()->where('user_id', $user->id)->value('timezone'), 'tutor_profile'],
            [config('tutor.timezone'), 'default'],
        ];

        foreach ($candidates as [$zone, $source]) {
            if (is_string($zone) && $zone !== '') {
                try {
                    new DateTimeZone($zone);

                    return [$zone, $source];
                } catch (Throwable) {
                }
            }
        }

        return ['UTC', 'default'];
    }
}
