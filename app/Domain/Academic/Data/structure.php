<?php

/**
 * Reference academic structure: countries, qualification frameworks, stages,
 * curricula and their ordered levels. Data, not code: operators edit all of it
 * at /operator/academic, and `academic:sync` only ever inserts what is missing
 * (matched on code), so an operator's edits are never overwritten.
 *
 * Grade R is not seeded (the brief starts at Grade 1); an operator can add it.
 * Level aliases feed the NeedParser; grade numbers ("Grade 11", "Gr 11",
 * "Graad 11", "matric") are recognised by pattern, not listed here.
 */
$grades = static function (string $prefix = 'grade'): array {
    $stageFor = static fn (int $n): string => match (true) {
        $n <= 3 => 'foundation',
        $n <= 6 => 'intermediate',
        $n <= 9 => 'senior',
        default => 'fet',
    };
    $nqf = static fn (int $n): ?int => match ($n) {
        10 => 2,
        11 => 3,
        12 => 4,
        9 => 1,
        default => null,
    };

    $levels = [];
    foreach (range(1, 12) as $n) {
        $levels[] = [
            'code' => "{$prefix}-{$n}",
            'name' => "Grade {$n}",
            'short_name' => "Gr {$n}",
            'stage' => $stageFor($n),
            'nqf_level' => $nqf($n),
            'aliases' => $n === 12 ? ['matric', 'matriek'] : [],
        ];
    }

    return $levels;
};

return [
    'countries' => [
        ['code' => 'ZA', 'name' => 'South Africa', 'sort' => 10],
    ],

    'frameworks' => [
        ['code' => 'za-gfetqsf', 'country' => 'ZA', 'short_name' => 'GFETQSF',
            'name' => 'General and Further Education and Training Qualifications Sub-Framework',
            'authority' => 'Umalusi', 'description' => 'School qualifications on the South African NQF, including the National Senior Certificate (NQF level 4).'],
        ['code' => 'za-heqsf', 'country' => 'ZA', 'short_name' => 'HEQSF',
            'name' => 'Higher Education Qualifications Sub-Framework',
            'authority' => 'Council on Higher Education', 'description' => 'Higher education qualifications on the South African NQF (levels 5 to 10).'],
        ['code' => 'cambridge-international', 'country' => null, 'short_name' => 'Cambridge',
            'name' => 'Cambridge International Education pathway',
            'authority' => 'Cambridge University Press & Assessment', 'description' => 'International school curriculum and qualifications, offered in many countries.'],
    ],

    'stages' => [
        ['code' => 'foundation', 'name' => 'Foundation Phase', 'kind' => 'school', 'sort' => 10],
        ['code' => 'intermediate', 'name' => 'Intermediate Phase', 'kind' => 'school', 'sort' => 20],
        ['code' => 'senior', 'name' => 'Senior Phase', 'kind' => 'school', 'sort' => 30],
        ['code' => 'fet', 'name' => 'Further Education and Training Phase', 'kind' => 'school', 'sort' => 40],
        ['code' => 'cambridge-primary', 'name' => 'Cambridge Primary', 'kind' => 'school', 'sort' => 50],
        ['code' => 'cambridge-lower-secondary', 'name' => 'Cambridge Lower Secondary', 'kind' => 'school', 'sort' => 60],
        ['code' => 'cambridge-upper-secondary', 'name' => 'Cambridge Upper Secondary', 'kind' => 'school', 'sort' => 70],
        ['code' => 'cambridge-advanced', 'name' => 'Cambridge Advanced', 'kind' => 'school', 'sort' => 80],
        ['code' => 'undergraduate', 'name' => 'Undergraduate', 'kind' => 'tertiary', 'sort' => 100],
        ['code' => 'honours', 'name' => 'Honours', 'kind' => 'tertiary', 'sort' => 110],
        ['code' => 'pg_diploma', 'name' => 'Postgraduate diploma', 'kind' => 'tertiary', 'sort' => 120],
        ['code' => 'masters', 'name' => "Master's", 'kind' => 'tertiary', 'sort' => 130],
        ['code' => 'doctoral', 'name' => 'Doctoral', 'kind' => 'tertiary', 'sort' => 140],
        ['code' => 'professional', 'name' => 'Professional', 'kind' => 'tertiary', 'sort' => 150],
    ],

    'curricula' => [
        [
            'code' => 'za-caps', 'country' => 'ZA', 'framework' => 'za-gfetqsf', 'kind' => 'school', 'sort' => 10,
            'name' => 'Curriculum and Assessment Policy Statement', 'short_name' => 'CAPS',
            'authority' => 'Department of Basic Education',
            'description' => 'The national school curriculum for public schools, Grade R to 12; Grade 12 ends in the National Senior Certificate written by the DBE.',
            'levels' => $grades(),
        ],
        [
            'code' => 'za-ieb', 'country' => 'ZA', 'framework' => 'za-gfetqsf', 'kind' => 'school', 'sort' => 20,
            'name' => 'Independent Examinations Board pathway', 'short_name' => 'IEB',
            'authority' => 'Independent Examinations Board',
            'description' => 'Independent schools whose learners write the National Senior Certificate set by the IEB. Subjects follow CAPS, with IEB additions such as Advanced Programme Mathematics.',
            'levels' => $grades(),
        ],
        [
            'code' => 'za-higher-education', 'country' => 'ZA', 'framework' => 'za-heqsf', 'kind' => 'tertiary', 'sort' => 30,
            'name' => 'South African higher education', 'short_name' => 'Higher education',
            'authority' => 'Council on Higher Education',
            'description' => 'University and college study, from first year to doctorate, plus professional qualifications and board examinations.',
            'levels' => [
                ['code' => 'undergraduate-1', 'name' => 'Undergraduate year 1', 'short_name' => '1st year', 'stage' => 'undergraduate', 'nqf_level' => null,
                    'aliases' => ['first year', '1st year', 'first year university', 'eerstejaar', 'undergraduate year 1']],
                ['code' => 'undergraduate-2', 'name' => 'Undergraduate year 2', 'short_name' => '2nd year', 'stage' => 'undergraduate', 'nqf_level' => null,
                    'aliases' => ['second year', '2nd year', 'tweedejaar', 'undergraduate year 2']],
                ['code' => 'undergraduate-3', 'name' => 'Undergraduate year 3', 'short_name' => '3rd year', 'stage' => 'undergraduate', 'nqf_level' => null,
                    'aliases' => ['third year', '3rd year', 'final year', 'derdejaar', 'undergraduate year 3']],
                ['code' => 'undergraduate-4', 'name' => 'Undergraduate year 4', 'short_name' => '4th year', 'stage' => 'undergraduate', 'nqf_level' => null,
                    'aliases' => ['fourth year', '4th year', 'vierdejaar', 'undergraduate year 4']],
                ['code' => 'honours', 'name' => 'Honours', 'short_name' => 'Honours', 'stage' => 'honours', 'nqf_level' => 8,
                    'aliases' => ['honours', 'hons', 'honors', 'honneurs']],
                ['code' => 'pg-diploma', 'name' => 'Postgraduate diploma', 'short_name' => 'PG diploma', 'stage' => 'pg_diploma', 'nqf_level' => 8,
                    'aliases' => ['postgraduate diploma', 'post graduate diploma', 'pgdip', 'pg dip', 'pg diploma', 'nagraadse diploma']],
                ['code' => 'masters', 'name' => "Master's", 'short_name' => "Master's", 'stage' => 'masters', 'nqf_level' => 9,
                    'aliases' => ['masters', 'master s', 'msc', 'mcom', 'meng', 'mphil', 'mba', 'meesters', 'mestrado']],
                ['code' => 'doctoral', 'name' => 'Doctoral', 'short_name' => 'Doctorate', 'stage' => 'doctoral', 'nqf_level' => 10,
                    'aliases' => ['doctoral', 'doctorate', 'phd', 'dphil', 'doktorsgraad', 'doutorado', 'doctorat']],
                ['code' => 'professional', 'name' => 'Professional qualification', 'short_name' => 'Professional', 'stage' => 'professional', 'nqf_level' => null,
                    'aliases' => ['professional qualification', 'professional exam', 'board exam', 'board exams', 'board examination']],
            ],
        ],
        [
            'code' => 'cambridge', 'country' => null, 'framework' => 'cambridge-international', 'kind' => 'school', 'sort' => 40,
            'name' => 'Cambridge International', 'short_name' => 'Cambridge',
            'authority' => 'Cambridge University Press & Assessment',
            'description' => 'International curriculum offered by schools in many countries: Primary, Lower Secondary, IGCSE, and AS & A Level.',
            'levels' => array_merge(
                array_map(static fn (int $n): array => [
                    'code' => "primary-stage-{$n}", 'name' => "Cambridge Primary Stage {$n}", 'short_name' => "Primary {$n}",
                    'stage' => 'cambridge-primary', 'nqf_level' => null, 'aliases' => ["cambridge primary stage {$n}", "primary stage {$n}"],
                ], range(1, 6)),
                array_map(static fn (int $n): array => [
                    'code' => "lower-secondary-stage-{$n}", 'name' => "Cambridge Lower Secondary Stage {$n}", 'short_name' => "Lower Sec {$n}",
                    'stage' => 'cambridge-lower-secondary', 'nqf_level' => null, 'aliases' => ["cambridge lower secondary stage {$n}", "lower secondary stage {$n}"],
                ], range(7, 9)),
                [
                    ['code' => 'igcse', 'name' => 'Cambridge IGCSE', 'short_name' => 'IGCSE', 'stage' => 'cambridge-upper-secondary', 'nqf_level' => null,
                        'aliases' => ['igcse', 'cambridge igcse']],
                    ['code' => 'as-level', 'name' => 'Cambridge AS Level', 'short_name' => 'AS Level', 'stage' => 'cambridge-advanced', 'nqf_level' => null,
                        'aliases' => ['as level', 'as levels', 'cambridge as level']],
                    ['code' => 'a-level', 'name' => 'Cambridge A Level', 'short_name' => 'A Level', 'stage' => 'cambridge-advanced', 'nqf_level' => null,
                        'aliases' => ['a level', 'a levels', 'cambridge a level']],
                ],
            ),
        ],
    ],

    /*
     * Legacy `subjects.level` strings (the pre-R-47 free text) mapped onto levels
     * in every seeded curriculum where the band applies, because a legacy row does
     * not say which curriculum it meant. Unknown strings are left unmapped and
     * reported by `academic:sync`; no row is changed or dropped.
     *
     * @var array<string, array<string, list<string>>> legacy level => curriculum code => level codes
     */
    'legacy_levels' => [
        'primary' => [
            'za-caps' => array_map(static fn (int $n): string => "grade-{$n}", range(1, 7)),
            'za-ieb' => array_map(static fn (int $n): string => "grade-{$n}", range(1, 7)),
            'cambridge' => array_merge(array_map(static fn (int $n): string => "primary-stage-{$n}", range(1, 6)), ['lower-secondary-stage-7']),
        ],
        'high_school' => [
            'za-caps' => array_map(static fn (int $n): string => "grade-{$n}", range(8, 12)),
            'za-ieb' => array_map(static fn (int $n): string => "grade-{$n}", range(8, 12)),
            'cambridge' => ['lower-secondary-stage-8', 'lower-secondary-stage-9', 'igcse', 'as-level', 'a-level'],
        ],
        'university' => [
            'za-higher-education' => ['undergraduate-1', 'undergraduate-2', 'undergraduate-3', 'undergraduate-4', 'honours', 'pg-diploma', 'masters', 'doctoral'],
        ],
        'adult' => [
            'za-higher-education' => ['professional'],
        ],
    ],
];
