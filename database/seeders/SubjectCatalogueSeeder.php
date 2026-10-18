<?php

namespace Database\Seeders;

use App\Models\Subject;
use Illuminate\Database\Seeder;

/**
 * A starter subject catalogue so a fresh deploy lets tutors apply at all
 * (applicants choose from the catalogue; with none, the form has nothing to
 * choose). Subjects only: no tutors, no sessions, nothing pretending to be
 * activity. Idempotent and edit-safe: matches on name + level, so it never
 * re-activates or renames something an operator changed. Operators manage the
 * catalogue afterwards at /operator/subjects.
 */
class SubjectCatalogueSeeder extends Seeder
{
    public function run(): void
    {
        $catalogue = [
            'high_school' => [
                'Mathematics', 'Mathematical Literacy', 'Physical Sciences', 'Life Sciences',
                'Accounting', 'Business Studies', 'Economics', 'Geography', 'History',
                'English', 'Afrikaans', 'Information Technology',
            ],
            'primary' => ['Mathematics', 'English', 'Natural Sciences'],
            'university' => ['Calculus', 'Statistics', 'Programming'],
        ];

        foreach ($catalogue as $level => $names) {
            foreach ($names as $name) {
                Subject::query()->firstOrCreate(
                    ['name' => $name, 'level' => $level],
                    ['is_active' => true],
                );
            }
        }
    }
}
