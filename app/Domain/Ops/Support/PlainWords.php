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

    /**
     * Audit context in plain words (D-2407): "Reason: Ended by the tutor; Amount: R50.00". Empty and "no" values are
     * left out (they said "Note: none; Again: no"), money in minor units is formatted, word-like codes read as words,
     * lists of ids are joined. The raw context stays in the log.
     */
    public static function details(array $context, int $limit = 6): string
    {
        return collect($context)
            ->reject(fn ($value) => $value === null || $value === false || $value === '' || $value === [])
            ->take($limit)
            ->map(function ($value, $key) {
                $key = (string) $key;
                if (str_ends_with($key, '_minor') && is_int($value)) {
                    return self::sentence(substr($key, 0, -6)).': '.\App\Domain\Money\Money::of($value, \App\Domain\Money\Money::defaultCurrency())->format();
                }
                $value = match (true) {
                    $value === true => 'yes',
                    is_string($value) => self::value($value),
                    is_scalar($value) => (string) $value,
                    is_array($value) && array_is_list($value) && collect($value)->every(fn ($v) => is_scalar($v)) => implode(', ', array_map(fn ($v) => is_string($v) ? self::value($v) : (string) $v, $value)),
                    default => json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                };

                return self::sentence(preg_replace('/_ids?$/', '', $key) ?: $key).': '.$value;
            })->join('; ');
    }

    /** Codes that read better as a phrase than as their words. */
    private const VALUES = [
        'tutor_ended' => 'Ended by the tutor', 'learner_ended' => 'Ended by the learner', 'no_show' => 'Did not come',
        'tutor_no_show' => 'Tutor did not come', 'learner_no_show' => 'Learner did not come',
    ];

    /** "tutor_ended" -> "Ended by the tutor"; "pending_payment" -> "Pending payment"; free text stays as written. */
    public static function value(string $value): string
    {
        if (isset(self::VALUES[$value])) {
            return self::VALUES[$value];
        }

        return preg_match('/^[a-z]+(?:[_.][a-z0-9]+)+$/', $value) === 1 ? self::sentence($value) : $value;
    }

    /**
     * What each audit entry is about, by name (D-2407): "Lerato Khumalo", "Lerato Khumalo's tutor profile",
     * "Mathematics lesson, 12 Nov", "Incident INC-...". One query per kind; anything unknown reads "Kind 12".
     *
     * @param  iterable<object{subject_type: ?string, subject_id: mixed}>  $entries
     * @return array<string, string> "type|id" => label
     */
    public static function subjects(iterable $entries, ?string $zone = null): array
    {
        $byKind = [];
        foreach ($entries as $e) {
            if ($e->subject_type && $e->subject_id !== null && is_numeric($e->subject_id)) {
                $byKind[Str::snake(class_basename(str_replace('.', '\\', $e->subject_type)))][$e->subject_type][] = (int) $e->subject_id;
            }
        }

        $labels = [];
        $put = function (string $kind, array $types, callable $resolve) use (&$labels) {
            $ids = array_values(array_unique(array_merge(...array_values($types))));
            $names = $resolve($ids);
            foreach (array_keys($types) as $type) {
                foreach ($names as $id => $label) {
                    $labels[$type.'|'.$id] = $label;
                }
            }
        };
        $zone ??= \App\Domain\Student\Support\Times::zone();

        foreach ($byKind as $kind => $types) {
            match ($kind) {
                'user' => $put($kind, $types, fn ($ids) => \App\Models\User::query()->whereIn('id', $ids)->pluck('name', 'id')->all()),
                'tutor_profile' => $put($kind, $types, fn ($ids) => \App\Models\TutorProfile::query()->with('user:id,name')->whereIn('id', $ids)->get()
                    ->mapWithKeys(fn ($p) => [$p->id => ($p->user?->name ? $p->user->name."'s tutor profile" : 'Tutor profile of a deleted account')])->all()),
                'classroom' => $put($kind, $types, fn ($ids) => \App\Domain\Classroom\Models\Classroom::query()->with('session.subject:id,name')->whereIn('id', $ids)->get()
                    ->mapWithKeys(fn ($c) => [$c->id => self::lesson($c->session, $zone)])->all()),
                'tutor_session' => $put($kind, $types, fn ($ids) => \App\Models\TutorSession::query()->with('subject:id,name')->whereIn('id', $ids)->get()
                    ->mapWithKeys(fn ($s) => [$s->id => self::lesson($s, $zone)])->all()),
                'incident' => $put($kind, $types, fn ($ids) => \App\Domain\Safety\Models\Incident::query()->whereIn('id', $ids)->pluck('reference', 'id')
                    ->map(fn ($ref) => 'Incident '.$ref)->all()),
                default => null,
            };
        }

        return $labels;
    }

    /** "Lerato Khumalo's tutor profile", or "Kind 12" when the record is gone or of a kind without a name. */
    public static function subject(?string $type, mixed $id, array $labels): string
    {
        if ($type === null || $type === '') {
            return '';
        }

        return $labels[$type.'|'.$id] ?? trim(self::thing($type).' '.$id);
    }

    private static function lesson(?object $session, string $zone): string
    {
        if ($session === null) {
            return 'A deleted lesson';
        }
        $subject = $session->subject?->name;
        $day = $session->starts_at?->copy()->timezone($zone)->format('j M');

        return trim(($subject ? $subject.' lesson' : 'Lesson').($day ? ', '.$day : ''));
    }

    private static function sentence(string $words): string
    {
        $words = trim(preg_replace('/\s+/', ' ', str_replace(['_', '-', ':'], ' ', $words)));

        return Str::ucfirst(Str::lower($words));
    }
}
