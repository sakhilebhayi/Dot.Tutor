<?php

namespace App\Domain\Comms;

/**
 * Reads the category table in config/comms.php: which category a notification type belongs to, which extra
 * channels it may use, which are mandatory (cannot be switched off), urgent (ignore quiet hours) or marketing
 * (never to a minor), and the defaults.
 */
final class CommsCategories
{
    public const EXTRA_CHANNELS = ['push', 'email', 'sms'];

    /** Categories only tutors see on their settings page. */
    public const TUTOR_ONLY = ['teaching', 'tutor_account', 'earnings'];

    /** @return array<string, array<string, mixed>> */
    public function all(): array
    {
        return (array) config('comms.categories', []);
    }

    public function exists(string $category): bool
    {
        return isset($this->all()[$category]);
    }

    public function categoryFor(string $type): string
    {
        $category = (string) (config('comms.types.'.$type) ?? 'general');

        return $this->exists($category) ? $category : 'general';
    }

    public function isMandatory(string $category): bool
    {
        return (bool) ($this->all()[$category]['mandatory'] ?? false);
    }

    public function isUrgent(string $category): bool
    {
        return (bool) ($this->all()[$category]['urgent'] ?? false);
    }

    public function isMarketing(string $category): bool
    {
        return (bool) ($this->all()[$category]['marketing'] ?? false);
    }

    /** @return list<string> */
    public function channels(string $category): array
    {
        return array_values(array_intersect(self::EXTRA_CHANNELS, (array) ($this->all()[$category]['channels'] ?? [])));
    }

    public function allows(string $category, string $channel): bool
    {
        return in_array($channel, $this->channels($category), true);
    }

    public function default(string $category, string $channel): bool
    {
        return (bool) ($this->all()[$category]['defaults'][$channel] ?? false);
    }

    public function label(string $category): string
    {
        return (string) ($this->all()[$category]['label'] ?? ucfirst(str_replace('_', ' ', $category)));
    }

    public function isInAppOnlyType(string $type): bool
    {
        return in_array($type, (array) config('comms.in_app_only_types', []), true);
    }
}
