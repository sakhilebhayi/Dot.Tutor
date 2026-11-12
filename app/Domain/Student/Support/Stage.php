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

    public static function colour(?string $stageCode): ?string
    {
        return $stageCode === null ? null : (self::COLOURS[$stageCode] ?? null);
    }
}
