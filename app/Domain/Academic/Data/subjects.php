<?php

/**
 * Reference subject catalogue and offerings (which subject at which level of
 * which curriculum).
 *
 * The catalogue is global: one "Mathematics" row, offered at many levels in
 * many curricula. Subjects are matched on slug, then on a case-insensitive
 * name, so an existing legacy row with the same name is ADOPTED (its id is
 * kept) rather than duplicated.
 *
 * CAPS offerings follow the DBE's phase subject lists (Foundation: languages,
 * Mathematics, Life Skills; Intermediate: + Natural Sciences and Technology,
 * Social Sciences; Senior: + Natural Sciences, Social Sciences, Technology,
 * EMS, Life Orientation, Creative Arts; FET: languages, Mathematics or
 * Mathematical Literacy or Technical Mathematics, Life Orientation and the
 * elective subjects listed below). Second Additional Language and the smaller
 * FET electives (Marine Sciences, the technology specialisations, etc.) are
 * not seeded; operators add them. IEB levels carry the same subjects (IEB
 * schools follow CAPS subjects) plus Advanced Programme Mathematics at FET.
 *
 * Aliases are lower-case, accent-free phrases for the NeedParser: common
 * abbreviations and names in Afrikaans (af), French (fr), Portuguese (pt),
 * Swahili (sw) and isiZulu (zu) where the build agent was confident of them.
 */
$languageSubjects = [];
$languageNames = [
    'en' => ['English', ['engels']],
    'af' => ['Afrikaans', []],
    'zu' => ['isiZulu', ['zulu']],
    'xh' => ['isiXhosa', ['xhosa']],
    'nso' => ['Sepedi', ['pedi']],
    'tn' => ['Setswana', ['tswana']],
    'st' => ['Sesotho', ['sotho']],
    'ts' => ['Xitsonga', ['tsonga']],
    'ss' => ['siSwati', ['swati']],
    've' => ['Tshivenda', ['venda']],
    'nr' => ['isiNdebele', ['ndebele']],
];
foreach ($languageNames as $code => [$name, $short]) {
    $lower = mb_strtolower($name);
    $variants = array_merge([$lower], $short);
    $languageSubjects[] = [
        'slug' => "{$code}-home-language", 'name' => "{$name} Home Language", 'family' => "language-{$code}", 'category' => 'languages',
        'language' => $code, 'tier' => 'home',
        'aliases' => array_merge(
            array_map(static fn (string $v): string => "{$v} home language", $variants),
            array_map(static fn (string $v): string => "{$v} hl", $variants),
            $code === 'af' ? ['afrikaans huistaal'] : [],
            $code === 'en' ? ['engels huistaal'] : [],
        ),
    ];
    $languageSubjects[] = [
        'slug' => "{$code}-first-additional-language", 'name' => "{$name} First Additional Language", 'family' => "language-{$code}", 'category' => 'languages',
        'language' => $code, 'tier' => 'first_additional',
        'aliases' => array_merge(
            array_map(static fn (string $v): string => "{$v} first additional language", $variants),
            array_map(static fn (string $v): string => "{$v} fal", $variants),
            $code === 'af' ? ['afrikaans eerste addisionele taal'] : [],
            $code === 'en' ? ['engels eerste addisionele taal'] : [],
        ),
    ];
}

$grades = static fn (int $from, int $to): array => array_map(static fn (int $n): string => "grade-{$n}", range($from, $to));
$allLanguageSlugs = array_column($languageSubjects, 'slug');
$undergrad = ['undergraduate-1', 'undergraduate-2', 'undergraduate-3', 'undergraduate-4'];
$postgrad = ['honours', 'pg-diploma', 'masters', 'doctoral'];

$capsLike = [
    ['levels' => $grades(1, 3), 'subjects' => array_merge($allLanguageSlugs, ['mathematics', 'life-skills']), 'compulsory' => ['mathematics', 'life-skills']],
    ['levels' => $grades(4, 6), 'subjects' => array_merge($allLanguageSlugs, ['mathematics', 'natural-sciences-and-technology', 'social-sciences', 'life-skills']),
        'compulsory' => ['mathematics', 'natural-sciences-and-technology', 'social-sciences', 'life-skills']],
    ['levels' => $grades(7, 9), 'subjects' => array_merge($allLanguageSlugs, ['mathematics', 'natural-sciences', 'social-sciences', 'technology', 'economic-and-management-sciences', 'life-orientation', 'creative-arts']),
        'compulsory' => ['mathematics', 'natural-sciences', 'social-sciences', 'technology', 'economic-and-management-sciences', 'life-orientation', 'creative-arts']],
    ['levels' => $grades(10, 12), 'subjects' => array_merge($allLanguageSlugs, [
        'mathematics', 'mathematical-literacy', 'technical-mathematics', 'life-orientation',
        'physical-sciences', 'life-sciences', 'technical-sciences', 'accounting', 'business-studies', 'economics',
        'geography', 'history', 'information-technology', 'computer-applications-technology',
        'agricultural-sciences', 'consumer-studies', 'tourism', 'hospitality-studies',
        'engineering-graphics-and-design', 'dramatic-arts', 'visual-arts', 'music', 'religion-studies',
    ]), 'compulsory' => ['life-orientation']],
];

return [
    'subjects' => array_merge($languageSubjects, [
        // General language subjects (legacy "English" / "Afrikaans" rows are adopted by these; Cambridge and tertiary use them).
        ['slug' => 'english', 'name' => 'English', 'family' => 'language-en', 'category' => 'languages', 'language' => 'en', 'tier' => null, 'aliases' => ['english', 'engels', 'anglais', 'ingles', 'kiingereza', 'isingisi']],
        ['slug' => 'afrikaans', 'name' => 'Afrikaans', 'family' => 'language-af', 'category' => 'languages', 'language' => 'af', 'tier' => null, 'aliases' => ['afrikaans']],

        ['slug' => 'mathematics', 'name' => 'Mathematics', 'family' => 'mathematics', 'category' => 'mathematics',
            'aliases' => ['mathematics', 'maths', 'math', 'wiskunde', 'mathematiques', 'matematica', 'hisabati', 'izibalo', 'core maths', 'pure maths', 'pure mathematics', 'algebra', 'geometry', 'trigonometry', 'trig']],
        ['slug' => 'mathematical-literacy', 'name' => 'Mathematical Literacy', 'family' => 'mathematics', 'category' => 'mathematics',
            'aliases' => ['mathematical literacy', 'maths literacy', 'math literacy', 'maths lit', 'math lit', 'mathlit', 'wiskundige geletterdheid']],
        ['slug' => 'technical-mathematics', 'name' => 'Technical Mathematics', 'family' => 'mathematics', 'category' => 'mathematics',
            'aliases' => ['technical mathematics', 'technical maths', 'tech maths', 'tegniese wiskunde']],
        ['slug' => 'advanced-programme-mathematics', 'name' => 'Advanced Programme Mathematics', 'family' => 'mathematics', 'category' => 'mathematics',
            'aliases' => ['advanced programme mathematics', 'ap maths', 'ap mathematics', 'further studies mathematics']],
        ['slug' => 'further-mathematics', 'name' => 'Further Mathematics', 'family' => 'mathematics', 'category' => 'mathematics',
            'aliases' => ['further mathematics', 'further maths']],
        ['slug' => 'calculus', 'name' => 'Calculus', 'family' => 'mathematics', 'category' => 'mathematics', 'aliases' => ['calculus', 'calc']],
        ['slug' => 'linear-algebra', 'name' => 'Linear Algebra', 'family' => 'mathematics', 'category' => 'mathematics', 'aliases' => ['linear algebra']],
        ['slug' => 'statistics', 'name' => 'Statistics', 'family' => 'statistics', 'category' => 'mathematics', 'aliases' => ['statistics', 'stats', 'statistiek']],

        ['slug' => 'life-skills', 'name' => 'Life Skills', 'family' => 'life-skills', 'category' => 'life-skills', 'aliases' => ['life skills', 'lewensvaardighede']],
        ['slug' => 'life-orientation', 'name' => 'Life Orientation', 'family' => 'life-skills', 'category' => 'life-skills', 'aliases' => ['life orientation', 'lewensorientering']],
        ['slug' => 'natural-sciences-and-technology', 'name' => 'Natural Sciences and Technology', 'family' => 'natural-sciences', 'category' => 'sciences',
            'aliases' => ['natural sciences and technology', 'natural science and technology', 'ns and tech', 'natuurwetenskappe en tegnologie']],
        ['slug' => 'natural-sciences', 'name' => 'Natural Sciences', 'family' => 'natural-sciences', 'category' => 'sciences',
            'aliases' => ['natural sciences', 'natural science', 'natuurwetenskappe']],
        ['slug' => 'social-sciences', 'name' => 'Social Sciences', 'family' => 'social-sciences', 'category' => 'humanities',
            'aliases' => ['social sciences', 'social science', 'sosiale wetenskappe']],
        ['slug' => 'technology', 'name' => 'Technology', 'family' => 'technology', 'category' => 'technology', 'aliases' => ['technology', 'tegnologie']],
        ['slug' => 'economic-and-management-sciences', 'name' => 'Economic and Management Sciences', 'family' => 'commerce', 'category' => 'commerce',
            'aliases' => ['economic and management sciences', 'ems', 'ekonomiese en bestuurswetenskappe', 'ebw']],
        ['slug' => 'creative-arts', 'name' => 'Creative Arts', 'family' => 'arts', 'category' => 'arts', 'aliases' => ['creative arts', 'skeppende kunste']],

        ['slug' => 'physical-sciences', 'name' => 'Physical Sciences', 'family' => 'physical-sciences', 'category' => 'sciences',
            'aliases' => ['physical sciences', 'physical science', 'phys sci', 'physics and chemistry', 'fisiese wetenskappe', 'natuur en skeikunde']],
        ['slug' => 'technical-sciences', 'name' => 'Technical Sciences', 'family' => 'physical-sciences', 'category' => 'sciences', 'aliases' => ['technical sciences', 'technical science', 'tegniese wetenskappe']],
        ['slug' => 'life-sciences', 'name' => 'Life Sciences', 'family' => 'life-sciences', 'category' => 'sciences',
            'aliases' => ['life sciences', 'life science', 'life sci', 'lewenswetenskappe']],
        ['slug' => 'physics', 'name' => 'Physics', 'family' => 'physical-sciences', 'category' => 'sciences', 'aliases' => ['physics', 'fisika', 'physique', 'fisica', 'fizikia']],
        ['slug' => 'chemistry', 'name' => 'Chemistry', 'family' => 'physical-sciences', 'category' => 'sciences', 'aliases' => ['chemistry', 'chem', 'chemie', 'skeikunde', 'chimie', 'quimica', 'kemia']],
        ['slug' => 'biology', 'name' => 'Biology', 'family' => 'life-sciences', 'category' => 'sciences', 'aliases' => ['biology', 'bio', 'biologie', 'biologia', 'biolojia']],
        ['slug' => 'science', 'name' => 'Science', 'family' => 'natural-sciences', 'category' => 'sciences', 'aliases' => ['science', 'wetenskap', 'sayansi']],

        ['slug' => 'accounting', 'name' => 'Accounting', 'family' => 'accounting', 'category' => 'commerce', 'aliases' => ['accounting', 'accounts', 'rekeningkunde', 'comptabilite', 'contabilidade']],
        ['slug' => 'financial-accounting', 'name' => 'Financial Accounting', 'family' => 'accounting', 'category' => 'commerce', 'aliases' => ['financial accounting', 'fin acc', 'finansiele rekeningkunde']],
        ['slug' => 'management-accounting', 'name' => 'Management Accounting', 'family' => 'accounting', 'category' => 'commerce', 'aliases' => ['management accounting', 'man acc', 'bestuursrekeningkunde']],
        ['slug' => 'taxation', 'name' => 'Taxation', 'family' => 'accounting', 'category' => 'commerce', 'aliases' => ['taxation', 'tax', 'belasting']],
        ['slug' => 'auditing', 'name' => 'Auditing', 'family' => 'accounting', 'category' => 'commerce', 'aliases' => ['auditing', 'audit', 'ouditkunde']],
        ['slug' => 'business-studies', 'name' => 'Business Studies', 'family' => 'commerce', 'category' => 'commerce', 'aliases' => ['business studies', 'business', 'besigheidstudies']],
        ['slug' => 'economics', 'name' => 'Economics', 'family' => 'economics', 'category' => 'commerce', 'aliases' => ['economics', 'econ', 'ekonomie', 'economie', 'economia', 'uchumi']],

        ['slug' => 'geography', 'name' => 'Geography', 'family' => 'geography', 'category' => 'humanities', 'aliases' => ['geography', 'geo', 'geografie', 'geographie', 'geografia', 'jiografia']],
        ['slug' => 'history', 'name' => 'History', 'family' => 'history', 'category' => 'humanities', 'aliases' => ['history', 'geskiedenis', 'histoire', 'historia']],
        ['slug' => 'religion-studies', 'name' => 'Religion Studies', 'family' => 'religion-studies', 'category' => 'humanities', 'aliases' => ['religion studies', 'religious studies', 'godsdiensstudies']],
        ['slug' => 'tourism', 'name' => 'Tourism', 'family' => 'tourism', 'category' => 'services', 'aliases' => ['tourism', 'toerisme']],
        ['slug' => 'hospitality-studies', 'name' => 'Hospitality Studies', 'family' => 'hospitality', 'category' => 'services', 'aliases' => ['hospitality studies', 'hospitality', 'gasvryheidstudies']],
        ['slug' => 'consumer-studies', 'name' => 'Consumer Studies', 'family' => 'consumer-studies', 'category' => 'services', 'aliases' => ['consumer studies', 'verbruikerstudies']],
        ['slug' => 'agricultural-sciences', 'name' => 'Agricultural Sciences', 'family' => 'agriculture', 'category' => 'sciences', 'aliases' => ['agricultural sciences', 'agricultural science', 'agric', 'landbouwetenskappe']],

        ['slug' => 'information-technology', 'name' => 'Information Technology', 'family' => 'computing', 'category' => 'technology', 'aliases' => ['information technology', 'inligtingstegnologie']],
        ['slug' => 'computer-applications-technology', 'name' => 'Computer Applications Technology', 'family' => 'computing', 'category' => 'technology',
            'aliases' => ['computer applications technology', 'rekenaartoepassingstegnologie', 'rtt']],
        ['slug' => 'computer-science', 'name' => 'Computer Science', 'family' => 'computing', 'category' => 'technology', 'aliases' => ['computer science', 'comp sci', 'compsci', 'rekenaarwetenskap']],
        ['slug' => 'programming', 'name' => 'Programming', 'family' => 'computing', 'category' => 'technology', 'aliases' => ['programming', 'coding', 'programmering']],
        ['slug' => 'engineering-graphics-and-design', 'name' => 'Engineering Graphics and Design', 'family' => 'engineering', 'category' => 'technology',
            'aliases' => ['engineering graphics and design', 'egd', 'ingenieursgrafika en ontwerp', 'igo']],

        ['slug' => 'dramatic-arts', 'name' => 'Dramatic Arts', 'family' => 'arts', 'category' => 'arts', 'aliases' => ['dramatic arts', 'drama', 'dramatiese kunste']],
        ['slug' => 'visual-arts', 'name' => 'Visual Arts', 'family' => 'arts', 'category' => 'arts', 'aliases' => ['visual arts', 'art', 'visuele kunste']],
        ['slug' => 'music', 'name' => 'Music', 'family' => 'arts', 'category' => 'arts', 'aliases' => ['music', 'musiek', 'musique', 'musica', 'muziki']],

        ['slug' => 'academic-writing', 'name' => 'Academic Writing', 'family' => 'academic-skills', 'category' => 'academic-skills', 'aliases' => ['academic writing', 'akademiese skryf']],
        ['slug' => 'research-methods', 'name' => 'Research Methods', 'family' => 'academic-skills', 'category' => 'academic-skills', 'aliases' => ['research methods', 'research methodology', 'navorsingsmetodes']],
    ]),

    /*
     * Offerings: curriculum code => list of {levels, subjects, compulsory?}.
     * Only active, seeded offerings are listed; the absence of an offering
     * means "not seeded", which the NeedParser reports honestly.
     */
    'offerings' => [
        'za-caps' => $capsLike,
        'za-ieb' => array_merge($capsLike, [
            ['levels' => $grades(10, 12), 'subjects' => ['advanced-programme-mathematics']],
        ]),
        'za-higher-education' => [
            ['levels' => $undergrad, 'subjects' => [
                'mathematics', 'calculus', 'linear-algebra', 'statistics', 'physics', 'chemistry', 'biology',
                'financial-accounting', 'management-accounting', 'economics', 'computer-science', 'programming', 'academic-writing',
            ]],
            ['levels' => ['undergraduate-2', 'undergraduate-3', 'undergraduate-4'], 'subjects' => ['taxation', 'auditing']],
            ['levels' => $postgrad, 'subjects' => ['research-methods', 'academic-writing', 'statistics']],
            ['levels' => ['professional'], 'subjects' => ['financial-accounting', 'management-accounting', 'taxation', 'auditing']],
        ],
        'cambridge' => [
            ['levels' => array_map(static fn (int $n): string => "primary-stage-{$n}", range(1, 6)), 'subjects' => ['english', 'mathematics', 'science']],
            ['levels' => array_map(static fn (int $n): string => "lower-secondary-stage-{$n}", range(7, 9)), 'subjects' => ['english', 'mathematics', 'science']],
            ['levels' => ['igcse'], 'subjects' => [
                'english', 'afrikaans', 'mathematics', 'physics', 'chemistry', 'biology', 'accounting', 'business-studies',
                'economics', 'geography', 'history', 'computer-science',
            ]],
            ['levels' => ['as-level', 'a-level'], 'subjects' => [
                'mathematics', 'further-mathematics', 'physics', 'chemistry', 'biology', 'accounting', 'business-studies',
                'economics', 'geography', 'history', 'computer-science',
            ]],
        ],
    ],
];
