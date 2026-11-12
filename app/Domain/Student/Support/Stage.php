<?php

namespace App\Domain\Student\Support;

/**
 * Maps a seeded academic stage code to the design system's eight binding colours (docs/R47_DS.md, Stages).
 * The colour is data: it says which shelf a level belongs to. Cambridge stages take the nearest South African phase.
 */
final class Stage
{
    private const COLOURS = [
        'foundation' => 'foundation',
        'intermediate' => 'intermediate',
        'senior' => 'senior',
        'fet' => 'fet',
        'undergraduate' => 'undergraduate',
        'honours' => 'postgraduate',
        'pg_diploma' => 'postgraduate',
        'masters' => 'postgraduate',
        'postgraduate' => 'postgraduate',
        'doctoral' => 'doctoral',
        'professional' => 'professional',
        'cambridge-primary' => 'intermediate',
        'cambridge-lower-secondary' => 'senior',
        'cambridge-upper-secondary' => 'fet',
        'cambridge-advanced' => 'fet',
    ];

    /** South African school phases by the grades they cover, the way learners say them (R-47 polish D-2026). */
    private const PLAIN = [
        'foundation' => 'Foundation Phase, Grades R to 3',
        'intermediate' => 'Intermediate Phase, Grades 4 to 6',
        'senior' => 'Senior Phase, Grades 7 to 9',
        'fet' => 'FET, Grades 10 to 12',
    ];

    /** "FET, Grades 10 to 12" instead of "Further Education and Training Phase"; other stages keep their name. */
    public static function label(?string $stageCode, ?string $name): string
    {
        return ($stageCode !== null ? (self::PLAIN[$stageCode] ?? null) : null) ?? (string) $name;
    }

    public static function colour(?string $stageCode): ?string
    {
        return $stageCode === null ? null : (self::COLOURS[$stageCode] ?? null);
    }
}
