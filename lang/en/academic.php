<?php

/*
 * Interface strings for the academic domain (learner onboarding, languages,
 * locale switcher, translations). English is the source; other locales live in
 * lang/<code>/academic.php and fall back to these keys when a string is missing.
 */
return [
    'onboarding' => [
        'title' => 'Your learning profile',
        'lede' => 'Tell us where you are in your studies and which languages work for you. It takes under a minute, and you can change it any time.',
        'describe_label' => 'Describe what you need',
        'describe_hint' => 'For example: Grade 11 Mathematics in isiZulu',
        'describe_button' => 'Fill in the form for me',
        'describe_result' => 'We filled in what we recognised from ":text". Check it before you save.',
        'describe_nothing' => 'We could not recognise a level or subject in ":text". Choose them below.',
        'country' => 'Country',
        'curriculum' => 'Curriculum',
        'curriculum_hint' => 'International curricula, such as Cambridge, are listed for every country.',
        'level' => 'Grade or level',
        'choose_level' => 'Choose your grade or level',
        'choose_level_first' => 'Choose your curriculum and level, then press Show subjects.',
        'show_subjects' => 'Show subjects',
        'subjects' => 'Subjects you want help with',
        'subjects_none' => 'No subjects are listed for this level yet.',
        'institution' => 'University or college (optional)',
        'institution_none' => 'Not listed or prefer not to say',
        'birth_month' => 'Month and year of birth',
        'birth_month_hint' => 'We keep only the month and year. It decides which safety protections apply to your account.',
        'birth_month_label_month' => 'Month',
        'birth_month_label_year' => 'Year',
        'languages_heading' => 'Languages',
        'teaching_languages' => 'Languages you want to be taught in',
        'teaching_languages_hint' => 'Tutors are matched on these, not on the language of this screen.',
        'understood_languages' => 'Other languages you understand',
        'learning_languages' => 'Languages you are learning',
        'bilingual' => 'Bilingual lessons',
        'bilingual_yes' => 'Fine with me (for example, explanations in isiZulu with terms in English)',
        'bilingual_no' => 'I prefer one language per lesson',
        'save' => 'Save my learning profile',
        'saved' => 'Your learning profile is saved.',
        'not_offered' => ':subject is not offered at :level in our catalogue yet.',
        'no_level' => 'No grade or level recognised.',
        'no_subject' => 'No subject recognised.',
    ],

    'locale' => [
        'title' => 'Language of this site',
        'lede' => 'This changes the words on screen only. To be matched with tutors who teach in a language, set your teaching languages in your learning profile.',
        'choose' => 'Choose a language',
        'apply' => 'Use this language',
        'changed' => 'The site language is now :language.',
        'complete' => 'Fully translated',
        'partial' => 'Partly translated; the rest is shown in English',
        'none' => 'Not translated yet, shown in English',
        'machine_note' => 'Drafted with AI and not yet checked by a native speaker.',
        'reviewed_note' => 'Checked by a native speaker.',
    ],

    'translation' => [
        'original' => 'Original (:language)',
        'translated' => 'Translation (:language)',
        'none' => 'No translation in this language yet.',
        'out_of_date' => 'The original changed after this was translated.',
        'notation_warning' => 'Check these against the original; they should appear unchanged: :tokens',
    ],

    'language_roles' => [
        'interface' => 'Site language',
        'understands' => 'Understands',
        'prefers_teaching' => 'Wants to be taught in',
        'learning' => 'Is learning',
        'can_teach_in' => 'Can teach in',
    ],

    'proficiency' => [
        'working' => 'Working knowledge',
        'fluent' => 'Fluent',
        'native' => 'First language',
    ],

    'provenance' => [
        'author' => 'Translated by the author',
        'human' => 'Human translation',
        'machine' => 'Machine translation',
    ],

    'review_status' => [
        'unreviewed' => 'Not reviewed',
        'approved' => 'Reviewed',
        'rejected' => 'Rejected',
    ],

    'months' => [
        1 => 'January', 2 => 'February', 3 => 'March', 4 => 'April', 5 => 'May', 6 => 'June',
        7 => 'July', 8 => 'August', 9 => 'September', 10 => 'October', 11 => 'November', 12 => 'December',
    ],

    'validation' => [
        'level_not_in_curriculum' => 'That level does not belong to the curriculum you chose.',
        'curriculum_not_in_country' => 'That curriculum is not available in the country you chose.',
        'subject_not_at_level' => 'One of the subjects is not offered at your level. Choose from the list.',
        'birth_month_future' => 'The month of birth cannot be in the future.',
    ],
];
