<?php

namespace App\Support;

/**
 * A value axis for the work-screen column charts (D-2211, dataviz rules): zero at the baseline, round even steps
 * (1, 2 or 5 times a power of ten, times the unit), at most four intervals, and the top tick above the largest value
 * so a bar never touches the plot's edge. Counts get integer ticks only, so an axis can never read "0, 1, 1".
 * For money pass the minor units per major unit as $unit (100), so ticks fall on whole rands.
 */
final class ChartScale
{
    /** @return array{step: int, top: int, ticks: list<int>} */
    public static function nice(int $max, int $unit = 1, int $maxIntervals = 4): array
    {
        $max = max(0, $max);
        $unit = max(1, $unit);

        $step = $unit;
        for ($pow = 1; ; $pow *= 10) {
            foreach ([1, 2, 5] as $k) {
                $candidate = $k * $pow * $unit;
                if (intdiv($max, $candidate) + 1 <= $maxIntervals) {
                    $step = $candidate;
                    break 2;
                }
            }
        }

        $top = $step * (intdiv($max, $step) + 1);

        return ['step' => $step, 'top' => $top, 'ticks' => range(0, $top, $step)];
    }
}
