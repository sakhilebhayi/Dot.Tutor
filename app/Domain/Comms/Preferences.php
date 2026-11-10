<?php

namespace App\Domain\Comms;

use App\Domain\Comms\Contracts\MinorRouting;
use App\Domain\Comms\Models\NotificationPreference;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use InvalidArgumentException;

/**
 * A person's channel choices per category (adapted from Dot.Ehail R-46). In-app is always on. Mandatory categories
 * (safety, payments, guardian approvals) are always on and cannot be changed. Marketing categories are never on
 * for a minor, whatever is stored.
 */
final class Preferences
{
    public function __construct(private readonly CommsCategories $categories, private readonly MinorRouting $minors) {}

    public function allows(User $user, string $category, string $channel): bool
    {
        if ($this->categories->isMarketing($category) && $this->minors->isMinor($user)) {
            return false;
        }

        if ($channel === 'in_app') {
            return true;
        }

        if (! $this->categories->allows($category, $channel)) {
            return false;
        }

        if ($this->categories->isMandatory($category)) {
            return true;
        }

        return $this->stored($user)[$category][$channel] ?? $this->categories->default($category, $channel);
    }

    /**
     * The categories this person sees on their settings page, with each channel's state.
     *
     * @return array<string, array{label: string, description: string, mandatory: bool, mandatory_reason: ?string, channels: array<string, array{enabled: bool, locked: bool}>}>
     */
    public function matrix(User $user): array
    {
        $isTutor = $user->tutorProfile()->exists();
        $isMinor = $this->minors->isMinor($user);
        $out = [];

        foreach ($this->categories->all() as $key => $definition) {
            if (in_array($key, CommsCategories::TUTOR_ONLY, true) && ! $isTutor) {
                continue;
            }
            if ($this->categories->isMarketing($key) && $isMinor) {
                continue;
            }

            $mandatory = $this->categories->isMandatory($key);
            $channels = [];

            foreach ($this->categories->channels($key) as $channel) {
                // SMS has no provider (decision D-1006): it is not offered as a choice at all.
                if ($channel === 'sms') {
                    continue;
                }
                $channels[$channel] = ['enabled' => $this->allows($user, $key, $channel), 'locked' => $mandatory];
            }

            $out[$key] = [
                'label' => (string) $definition['label'],
                'description' => (string) ($definition['description'] ?? ''),
                'mandatory' => $mandatory,
                'mandatory_reason' => $mandatory ? (string) ($definition['mandatory_reason'] ?? '') : null,
                'channels' => $channels,
            ];
        }

        return $out;
    }

    /**
     * @throws PreferenceLocked when the category is mandatory
     * @throws InvalidArgumentException for an unknown category or a channel it does not use
     */
    public function set(User $user, string $category, string $channel, bool $enabled): void
    {
        if (! $this->categories->exists($category) || ! $this->categories->allows($category, $channel) || $channel === 'sms') {
            throw new InvalidArgumentException("Unknown choice {$category}/{$channel}.");
        }

        if ($this->categories->isMandatory($category)) {
            throw new PreferenceLocked($category, (string) (config("comms.categories.{$category}.mandatory_reason") ?? ''));
        }

        if ($this->categories->isMarketing($category) && $enabled && $this->minors->isMinor($user)) {
            throw new PreferenceLocked($category, 'News from Dot.Tutor is not sent to learners under 18.');
        }

        NotificationPreference::updateOrCreate(
            ['user_id' => $user->id, 'category' => $category, 'channel' => $channel],
            ['enabled' => $enabled],
        );

        Cache::forget($this->cacheKey($user));
    }

    /** @return array<string, array<string, bool>> */
    private function stored(User $user): array
    {
        return Cache::remember($this->cacheKey($user), 300, function () use ($user) {
            $rows = [];
            foreach (NotificationPreference::where('user_id', $user->id)->get(['category', 'channel', 'enabled']) as $row) {
                $rows[$row->category][$row->channel] = (bool) $row->enabled;
            }

            return $rows;
        });
    }

    private function cacheKey(User $user): string
    {
        return 'comms.prefs.'.$user->id;
    }
}
