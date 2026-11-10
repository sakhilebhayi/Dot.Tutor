<?php

namespace Database\Seeders;

use App\Domain\Academic\Services\ReferenceDataSync;
use Illuminate\Database\Seeder;

/**
 * Reference data only (languages, academic structure, subject catalogue,
 * offerings, starter topics, public universities): no people, no activity.
 * Safe in production; the same code runs as `php artisan academic:sync`.
 */
class AcademicReferenceSeeder extends Seeder
{
    public function run(ReferenceDataSync $sync): void
    {
        $sync->run();
    }
}
