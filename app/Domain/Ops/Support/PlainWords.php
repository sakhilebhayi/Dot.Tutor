<?php

namespace App\Domain\Ops\Support;

use Illuminate\Support\Str;

/**
 * Operator screens say things in plain words (D-2209): an audit action key such as money.payout_batch_exported reads
 * "Payout batch exported", a model class such as TutorProfile reads "Tutor profile", and an actor reads as the
 * person's name instead of "user:60". The keys and classes stay in the log itself and in the docs.
 */
class PlainWords
{
    /** Leading segments of an action key that name the area that wrote it. */
    private const AREAS = [
        'money' => 'Money', 'safety' => 'Safety', 'marketplace' => 'Marketplace', 'learning' => 'Learning',
        'academic' => 'Academic structure', 'community' => 'Rooms', 'ai' => 'Assistant', 'comms' => 'Messages',
        'ops' => 'Operations', 'classroom' => 'Classroom', 'teach' => 'Tutor workspace', 'privacy' => 'Privacy',
    ];

    /** "money.payout_batch_exported" -> "Payout batch exported"; "tutor.profile.submitted" -> "Tutor profile submitted". */
    public static function action(string $key): string
    {
        $parts = explode('.', $key);
        if (count($parts) > 1 && isset(self::AREAS[$parts[0]])) {
            array_shift($parts);
        }

        return self::sentence(implode(' ', $parts));
    }

    /** The area that wrote an action key, or null when the key does not start with one. */
    public static function area(string $key): ?string
    {
        $first = explode('.', $key)[0];

        return str_contains($key, '.') ? (self::AREAS[$first] ?? null) : null;
    }

    /** "App\Models\TutorProfile" or "tutor_profile" -> "Tutor profile". */
    public static function thing(?string $type): string
    {
        if ($type === null || $type === '') {
            return '';
        }

        return self::sentence(Str::snake(class_basename(str_replace('.', '\\', $type))));
    }

    /**
     * Who did it: the person's name when the account still exists, "Account 60" when it does not, "The system" for
     * scheduled work.
     *
     * @param  array<int, string>  $names  user id => name
     */
    public static function actor(?int $actorId, ?string $label, array $names): string
    {
        if ($actorId !== null) {
            return $names[$actorId] ?? 'Account '.$actorId;
        }

        return in_array($label, [null, '', 'system'], true) ? 'The system' : self::sentence((string) $label);
    }

    /** Audit context as "Amount minor: 5000; Reason: duplicate" with plain values. */
    public static function details(array $context, int $limit = 6): string
    {
        return collect($context)->take($limit)->map(function ($value, $key) {
            $value = match (true) {
                is_bool($value) => $value ? 'yes' : 'no',
                $value === null => 'none',
                is_scalar($value) => (string) $value,
                default => json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            };

            return self::sentence((string) $key).': '.$value;
        })->join('; ');
    }

    private static function sentence(string $words): string
    {
        $words = trim(preg_replace('/\s+/', ' ', str_replace(['_', '-', ':'], ' ', $words)));

        return Str::ucfirst(Str::lower($words));
    }
}
