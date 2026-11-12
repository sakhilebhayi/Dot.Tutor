<?php

/**
 * Starter topics, seeded for a few core CAPS offerings only:
 *
 *  - Mathematics Grades 1 to 9: the five CAPS content areas.
 *  - Mathematics Grades 10, 11 and 12: the CAPS FET topics.
 *  - Physical Sciences Grade 10 and Life Sciences Grade 10: the CAPS knowledge
 *    areas / topics, with a few sub-topics for Physical Sciences.
 *
 * Everything else has no topics yet; operators add them at /operator/academic.
 * Topic names follow the CAPS documents as the build agent knows them; an
 * academic editor should check them against the current DBE documents.
 *
 * @var array<string, array<string, array<string, list<string|array{0: string, 1: list<string>}>>>> curriculum => subject slug => level code => topics
 */
$contentAreas = [
    'Numbers, operations and relationships',
    'Patterns, functions and algebra',
    'Space and shape (geometry)',
    'Measurement',
    'Data handling',
];

$maths = [];
foreach (range(1, 9) as $n) {
    $maths["grade-{$n}"] = $contentAreas;
}

$maths['grade-10'] = [
    'Algebraic expressions', 'Exponents', 'Number patterns', 'Equations and inequalities', 'Trigonometry',
    'Functions', 'Euclidean geometry and measurement', 'Analytical geometry', 'Finance and growth',
    'Statistics', 'Probability',
];
$maths['grade-11'] = [
    'Exponents and surds', 'Equations and inequalities', 'Number patterns', 'Analytical geometry', 'Functions',
    'Trigonometry', 'Euclidean geometry and measurement', 'Finance, growth and decay', 'Probability', 'Statistics',
];
$maths['grade-12'] = [
    'Number patterns, sequences and series', 'Functions and inverse functions', 'Exponential and logarithmic functions',
    'Finance, growth and decay', 'Trigonometry', 'Polynomials', 'Differential calculus', 'Analytical geometry',
    'Euclidean geometry', 'Statistics', 'Counting and probability',
];

return [
    'za-caps' => [
        'mathematics' => $maths,
        'physical-sciences' => [
            'grade-10' => [
                ['Matter and materials', ['Matter and classification', 'States of matter and the kinetic molecular theory', 'The atom', 'The periodic table', 'Chemical bonding']],
                ['Chemical change', ['Physical and chemical change', 'Representing chemical change', 'Reactions in aqueous solution', 'Quantitative aspects of chemical change']],
                ['Chemical systems', ['The hydrosphere']],
                ['Mechanics', ['Vectors and scalars', 'Motion in one dimension', 'Energy']],
                ['Waves, sound and light', ['Transverse pulses', 'Transverse waves', 'Longitudinal waves', 'Sound', 'Electromagnetic radiation']],
                ['Electricity and magnetism', ['Magnetism', 'Electrostatics', 'Electric circuits']],
            ],
        ],
        'life-sciences' => [
            'grade-10' => [
                'Chemistry of life', 'Cells: the basic units of life', 'Cell division: mitosis', 'Plant and animal tissues',
                'Organs', 'Support and transport systems in plants', 'Support systems in animals', 'Transport systems in mammals',
                'Biosphere to ecosystems', 'Biodiversity and classification', 'History of life on Earth',
            ],
        ],
    ],
];
