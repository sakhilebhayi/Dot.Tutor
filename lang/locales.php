<?php

/*
 * Who wrote each interface locale and whether a native speaker has reviewed it.
 * Coverage (how many English keys a locale translates) is measured from the
 * files, not declared here. `provenance`:
 *   source             the English source strings
 *   machine_unreviewed drafted by the R-47 build agent (an AI model), not yet checked by a native speaker
 *   reviewed           checked by a named native speaker (record who in docs/R47_I18N.md)
 * A locale with no entry and no files is shown as "not translated yet, shown in English".
 *
 * `interface_fully_keyed` stays false while screens still hold hard-coded
 * English (most pre-R-47 screens do). While it is false no locale other than
 * English is ever labelled "fully translated", even at 100% of the keys.
 */
return [
    'interface_fully_keyed' => false,
    'locales' => [
        'en' => ['provenance' => 'source', 'reviewed' => true],
        'af' => ['provenance' => 'machine_unreviewed', 'reviewed' => false],
        'fr' => ['provenance' => 'machine_unreviewed', 'reviewed' => false],
        'pt' => ['provenance' => 'machine_unreviewed', 'reviewed' => false],
    ],
];
