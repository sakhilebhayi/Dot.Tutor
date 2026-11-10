<?php

/**
 * Reference languages. BCP 47 codes. The eleven official written languages of
 * South Africa, South African Sign Language (official since 2023; seeded as a
 * signed teaching language, never an interface locale), and Portuguese, French
 * and Swahili.
 *
 * `aliases` are lower-case phrases the NeedParser recognises: English names,
 * own-language names, names in other seeded languages where known (af: Afrikaans,
 * fr: French, pt: Portuguese, sw: Swahili) and isiZulu/isiXhosa "nge-" forms
 * ("ngesiZulu" = "in isiZulu"). Phrases flagged in `teaching_phrases` mean
 * "taught in this language" on their own.
 *
 * @return list<array{code: string, name_en: string, name_native: string, script: ?string, modality: string, is_official_za: bool, sort: int, aliases: list<string>, teaching_phrases?: list<string>}>
 */
return [
    ['code' => 'en', 'name_en' => 'English', 'name_native' => 'English', 'script' => 'Latn', 'modality' => 'written', 'is_official_za' => true, 'sort' => 10,
        'aliases' => ['english', 'engels', 'anglais', 'ingles', 'kiingereza', 'isingisi'], 'teaching_phrases' => ['ngesingisi']],
    ['code' => 'zu', 'name_en' => 'isiZulu', 'name_native' => 'isiZulu', 'script' => 'Latn', 'modality' => 'written', 'is_official_za' => true, 'sort' => 20,
        'aliases' => ['isizulu', 'zulu', 'zoeloe', 'zoulou'], 'teaching_phrases' => ['ngesizulu']],
    ['code' => 'xh', 'name_en' => 'isiXhosa', 'name_native' => 'isiXhosa', 'script' => 'Latn', 'modality' => 'written', 'is_official_za' => true, 'sort' => 30,
        'aliases' => ['isixhosa', 'xhosa'], 'teaching_phrases' => ['ngesixhosa']],
    ['code' => 'af', 'name_en' => 'Afrikaans', 'name_native' => 'Afrikaans', 'script' => 'Latn', 'modality' => 'written', 'is_official_za' => true, 'sort' => 40,
        'aliases' => ['afrikaans']],
    ['code' => 'nso', 'name_en' => 'Sepedi', 'name_native' => 'Sesotho sa Leboa', 'script' => 'Latn', 'modality' => 'written', 'is_official_za' => true, 'sort' => 50,
        'aliases' => ['sepedi', 'pedi', 'sesotho sa leboa', 'northern sotho', 'noord sotho']],
    ['code' => 'tn', 'name_en' => 'Setswana', 'name_native' => 'Setswana', 'script' => 'Latn', 'modality' => 'written', 'is_official_za' => true, 'sort' => 60,
        'aliases' => ['setswana', 'tswana']],
    ['code' => 'st', 'name_en' => 'Sesotho', 'name_native' => 'Sesotho', 'script' => 'Latn', 'modality' => 'written', 'is_official_za' => true, 'sort' => 70,
        'aliases' => ['sesotho', 'sotho', 'southern sotho', 'suid sotho']],
    ['code' => 'ts', 'name_en' => 'Xitsonga', 'name_native' => 'Xitsonga', 'script' => 'Latn', 'modality' => 'written', 'is_official_za' => true, 'sort' => 80,
        'aliases' => ['xitsonga', 'tsonga']],
    ['code' => 'ss', 'name_en' => 'siSwati', 'name_native' => 'siSwati', 'script' => 'Latn', 'modality' => 'written', 'is_official_za' => true, 'sort' => 90,
        'aliases' => ['siswati', 'swati', 'swazi']],
    ['code' => 've', 'name_en' => 'Tshivenda', 'name_native' => 'Tshivenḓa', 'script' => 'Latn', 'modality' => 'written', 'is_official_za' => true, 'sort' => 100,
        'aliases' => ['tshivenda', 'venda']],
    ['code' => 'nr', 'name_en' => 'isiNdebele', 'name_native' => 'isiNdebele', 'script' => 'Latn', 'modality' => 'written', 'is_official_za' => true, 'sort' => 110,
        'aliases' => ['isindebele', 'ndebele', 'south ndebele']],
    ['code' => 'sfs', 'name_en' => 'South African Sign Language', 'name_native' => 'South African Sign Language', 'script' => null, 'modality' => 'signed', 'is_official_za' => true, 'sort' => 120,
        'aliases' => ['south african sign language', 'sasl', 'sign language', 'suid afrikaanse gebaretaal', 'gebaretaal']],
    ['code' => 'pt', 'name_en' => 'Portuguese', 'name_native' => 'Português', 'script' => 'Latn', 'modality' => 'written', 'is_official_za' => false, 'sort' => 200,
        'aliases' => ['portuguese', 'portugues', 'portugees', 'portugais', 'kireno']],
    ['code' => 'fr', 'name_en' => 'French', 'name_native' => 'Français', 'script' => 'Latn', 'modality' => 'written', 'is_official_za' => false, 'sort' => 210,
        'aliases' => ['french', 'francais', 'frans', 'frances', 'kifaransa']],
    ['code' => 'sw', 'name_en' => 'Swahili', 'name_native' => 'Kiswahili', 'script' => 'Latn', 'modality' => 'written', 'is_official_za' => false, 'sort' => 220,
        'aliases' => ['swahili', 'kiswahili', 'swahili language', 'suaili']],
];
