{{-- Public landing. Real content only: the academic structure Dot.Tutor is organised by, the languages it
     matches on, and how matching explains itself. No tutors, counts, reviews or logos are shown because none
     exist to show. Photographs are open-licence pictures of South African and other libraries, credited in the footer. --}}
@php
    $stages = [
        ['key' => 'foundation', 'name' => 'Foundation', 'span' => 'Grades 1 to 3',
            'line' => 'Reading, writing and numbers, with a calm screen, big type and a guardian alongside.',
            'subjects' => ['Home language', 'First additional language', 'Mathematics', 'Life skills']],
        ['key' => 'intermediate', 'name' => 'Intermediate', 'span' => 'Grades 4 to 6',
            'line' => 'New subjects arrive. Rooms keep each grade with its own work.',
            'subjects' => ['Mathematics', 'Natural sciences and technology', 'Social sciences', 'Home language', 'First additional language', 'Life skills']],
        ['key' => 'senior', 'name' => 'Senior', 'span' => 'Grades 7 to 9',
            'line' => 'The years that decide subject choices, with practice for every topic.',
            'subjects' => ['Mathematics', 'Natural sciences', 'Social sciences', 'Technology', 'Economic and management sciences', 'Languages']],
        ['key' => 'fet', 'name' => 'FET', 'span' => 'Grades 10 to 12',
            'line' => 'Matric subjects, past-paper practice and tutors who know the exam.',
            'subjects' => ['Mathematics', 'Physical sciences', 'Life sciences', 'Accounting', 'Geography', 'History', 'English', 'Afrikaans']],
        ['key' => 'undergraduate', 'name' => 'Undergraduate', 'span' => 'First year to final year',
            'line' => 'Module by module, with tutors a year or two ahead of you and lecturers who tutor.',
            'subjects' => ['Calculus', 'Statistics', 'Financial accounting', 'Chemistry', 'Programming', 'Academic writing']],
        ['key' => 'postgraduate', 'name' => 'Postgraduate', 'span' => "Honours, diplomas, master's",
            'line' => 'Proposals, methods and analysis, with people who have done the degree.',
            'subjects' => ['Research proposals', 'Research methods', 'Statistical analysis', 'Literature reviews']],
        ['key' => 'doctoral', 'name' => 'Doctoral', 'span' => 'PhD and doctorates',
            'line' => 'Specialist help with method, analysis and writing, at the pace of a thesis.',
            'subjects' => ['Methodology', 'Advanced statistics', 'Qualitative analysis', 'Thesis writing']],
        ['key' => 'professional', 'name' => 'Professional', 'span' => 'Work and lifelong learning',
            'line' => 'Professional exams, workplace skills and languages for work.',
            'subjects' => ['Board exam preparation', 'Coding', 'Business writing', 'Languages for work']],
    ];

    $roomHolds = ['Questions and answers', 'Study notes and revision', 'Practice questions', 'Tutors who teach this level', 'Lessons and courses'];

    $languages = [
        ['code' => 'en', 'own' => 'English', 'name' => 'English'],
        ['code' => 'zu', 'own' => 'isiZulu', 'name' => 'Zulu'],
        ['code' => 'xh', 'own' => 'isiXhosa', 'name' => 'Xhosa'],
        ['code' => 'af', 'own' => 'Afrikaans', 'name' => 'Afrikaans'],
        ['code' => 'nso', 'own' => 'Sesotho sa Leboa', 'name' => 'Sepedi'],
        ['code' => 'tn', 'own' => 'Setswana', 'name' => 'Tswana'],
        ['code' => 'st', 'own' => 'Sesotho', 'name' => 'Southern Sotho'],
        ['code' => 'ts', 'own' => 'Xitsonga', 'name' => 'Tsonga'],
        ['code' => 'ss', 'own' => 'siSwati', 'name' => 'Swati'],
        ['code' => 've', 'own' => 'Tshivenḓa', 'name' => 'Venda'],
        ['code' => 'nr', 'own' => 'isiNdebele', 'name' => 'Southern Ndebele'],
    ];
    $moreLanguages = [
        ['code' => 'pt', 'own' => 'Português', 'name' => 'Portuguese'],
        ['code' => 'fr', 'own' => 'Français', 'name' => 'French'],
        ['code' => 'sw', 'own' => 'Kiswahili', 'name' => 'Swahili'],
    ];

    $credits = [
        ['title' => 'The Hub, Chancellor Oppenheimer Library, University of Cape Town', 'creator' => 'Theresa Schoeman', 'licence' => 'CC0', 'url' => 'https://commons.wikimedia.org/wiki/File:The_Hub,_Chancellor_Oppenheimer_Library,_University_of_Cape_Town.jpg'],
        ['title' => 'Johannesburg, New Library (1935)', 'creator' => null, 'licence' => 'public domain', 'url' => 'https://commons.wikimedia.org/wiki/File:Johannesburg,_New_Library.JPG'],
        ['title' => 'National Library of South Africa, 1900', 'creator' => null, 'licence' => 'public domain', 'url' => 'https://commons.wikimedia.org/wiki/File:National_Library_of_South_Africa,_1900.jpg'],
        ['title' => 'Book spines, Town Library of Ipswich', 'creator' => 'Leutha', 'licence' => 'CC BY-SA 4.0', 'url' => 'https://commons.wikimedia.org/w/index.php?curid=137398271'],
    ];

    // The public tutor search (R-47 polish D-2003): a signed-out visitor sees approved tutors without signing in.
    $findHref = Route::has('marketplace.find') ? route('marketplace.find') : route('tutors.browse');
    $teachHref = route('tutor.profile.edit');
@endphp
<x-layouts::public
    title="Tutors by subject, level and language"
    description="Dot.Tutor matches learners from Grade 1 to doctoral study with tutors who teach their subject, at their level, in a language they understand, and shows why each one fits."
    :credits="$credits">

    {{-- First screen: the offer, the action, and an example of the request it starts from. --}}
    <section class="lp-hero" aria-labelledby="lp-title">
        <div class="td-wrap lp-hero__grid">
            <div class="lp-hero__copy">
                <h1 id="lp-title" class="lp-display">
                    <span class="lp-line"><span>Tell us what</span></span>
                    <span class="lp-line"><span>you're stuck on</span></span>
                </h1>
                <p class="lp-lede">We match tutors by subject, level and language, and show you why.</p>
                <div class="lp-actions">
                    <x-td.button variant="primary" size="lg" :href="$findHref" icon-end="arrow-right">Find a tutor</x-td.button>
                    <x-td.button size="lg" :href="$teachHref">Teach on Dot.Tutor</x-td.button>
                </div>
                <p class="lp-note">Sign in with your InfoDot account. Learners under 18 join with a parent or guardian.</p>
            </div>

            <figure class="lp-hero__media">
                <img src="{{ asset('images/landing/uct-library-hub.webp') }}" width="1100" height="734" alt="The Hub at the University of Cape Town's Chancellor Oppenheimer Library: study desks, shelves and a double-height reading hall" fetchpriority="high" decoding="async">
                <div class="lp-slip" data-ui>
                    <p class="lp-slip__head">Example request</p>
                    <p class="lp-slip__text" lang="en">Grade 11 Maths, explained in isiZulu, after school, under R250 an hour.</p>
                    <div class="lp-slip__tags">
                        <x-td.pill kind="stage" stage="fet">Grade 11</x-td.pill>
                        <x-td.pill>Mathematics</x-td.pill>
                        <x-td.language-chip code="zu" name="Zulu" endonym="isiZulu" role="teaches" />
                    </div>
                </div>
            </figure>
        </div>
    </section>

    {{-- The shelf: one book per stage. A horizontal accordion on wide screens, stacked on phones.
         Without script every book is open. --}}
    <section class="lp-section lp-rooms" id="rooms" aria-labelledby="rooms-title">
        <div class="td-wrap">
            <div class="lp-intro">
                <h2 id="rooms-title" class="lp-h2">A room for every level</h2>
                <p class="lp-intro__text">A Grade 1 reader and a doctoral candidate need different rooms. Each stage has its own shelf of subjects, so you only see work that fits your year.</p>
            </div>

            <div class="lp-shelf" data-lp-shelf>
                @foreach ($stages as $stage)
                    <div class="lp-book td-stage--{{ $stage['key'] }}" data-lp-book @if ($loop->index === 3) data-open @endif>
                        <h3 class="lp-book__heading">
                            <button type="button" class="lp-spine" aria-expanded="true" aria-controls="book-{{ $stage['key'] }}" id="spine-{{ $stage['key'] }}">
                                <span class="lp-spine__name">{{ $stage['name'] }}</span>
                                <span class="lp-spine__span">{{ $stage['span'] }}</span>
                            </button>
                        </h3>
                        <div class="lp-book__page" id="book-{{ $stage['key'] }}" role="region" aria-labelledby="spine-{{ $stage['key'] }}">
                            <p class="lp-book__line">{{ $stage['line'] }}</p>
                            <div class="lp-book__cols">
                                <div>
                                    <p class="lp-book__label">Subjects on this shelf include</p>
                                    <ul class="lp-book__subjects">
                                        @foreach ($stage['subjects'] as $subject)
                                            <li>{{ $subject }}</li>
                                        @endforeach
                                    </ul>
                                </div>
                                <div>
                                    <p class="lp-book__label">Every subject room holds</p>
                                    <ul class="lp-book__holds">
                                        @foreach ($roomHolds as $hold)
                                            <li><x-td.icon name="check" />{{ $hold }}</li>
                                        @endforeach
                                    </ul>
                                </div>
                            </div>
                        </div>
                    </div>
                @endforeach
            </div>
            <p class="lp-footnote">South African CAPS and IEB grades come first; Cambridge and other curricula sit on the same shelves.</p>
        </div>
    </section>

    {{-- How matching works: the request set large, the explanation laid over it. --}}
    <section class="lp-section lp-how" id="how" aria-labelledby="how-title">
        <div class="td-wrap lp-how__grid">
            <div class="lp-how__stack" aria-hidden="true">
                <span class="lp-how__line is-soft">Grade 11</span>
                <span class="lp-how__line">Mathematics</span>
                <span class="lp-how__line is-faint">in isiZulu</span>
                <span class="lp-how__line is-faint">after school</span>
            </div>
            <div class="lp-how__text">
                <h2 id="how-title" class="lp-h2">Matches that explain themselves</h2>
                <p>Write your request the way you would say it. Dot.Tutor checks subject, level, curriculum, language, times and budget, and tells you which ones fit and which do not.</p>
                <p>Language is matched on what a tutor can actually teach in, never on the language of your screen.</p>
            </div>
            <div class="lp-how__card" data-ui>
                <p class="lp-how__card-head">How a match explains itself <span>(example)</span></p>
                <x-td.match-reasons label="Example match reasons" :reasons="[
                    ['text' => 'Teaches Grade 11 Mathematics (CAPS)', 'met' => true],
                    ['text' => 'Teaches in isiZulu, checked by our team', 'met' => true],
                    ['text' => 'Has open times on weekdays after 15:00', 'met' => true],
                    ['text' => 'Charges R220 for an hour, under your R250', 'met' => true],
                    ['text' => 'Online only, no lessons in person', 'met' => false],
                ]" />
                <div class="lp-how__badges">
                    <x-td.verify-badge status="verified" level="qualified" />
                    <x-td.language-chip code="zu" name="Zulu" endonym="isiZulu" role="teaches" verified match />
                </div>
            </div>
        </div>
    </section>

    {{-- Who it is for: sheets that stack as you scroll, like cards in a catalogue drawer. --}}
    <section class="lp-section lp-people" aria-labelledby="people-title">
        <div class="td-wrap">
            <h2 id="people-title" class="lp-h2">Built for everyone at the table</h2>
        </div>
        <div class="td-wrap lp-sheets">
            <article class="lp-sheet lp-sheet--learn" aria-labelledby="sheet-learn">
                <div class="lp-sheet__text">
                    <h3 id="sheet-learn" class="lp-sheet__title">Learners, Grade 1 to PhD</h3>
                    <p>Younger learners get a calmer screen: bigger type, fewer choices, and a guardian alongside. Older students get every tool, from past papers to research methods.</p>
                </div>
                <img class="lp-sheet__img" src="{{ asset('images/landing/johannesburg-library-1935.webp') }}" width="960" height="634" alt="Johannesburg's new public library in 1935, seen from the square in front of it" loading="lazy" decoding="async">
            </article>

            <article class="lp-sheet lp-sheet--teach" id="teach" aria-labelledby="sheet-teach">
                <div class="lp-sheet__text">
                    <h3 id="sheet-teach" class="lp-sheet__title">Tutors build a practice</h3>
                    <p>Strong students tutor the grades behind them. Qualified teachers and lecturers run a business: lessons, groups, courses and study material, with bookings and earnings in one workspace.</p>
                    <x-td.button :href="$teachHref" variant="primary">Apply to teach</x-td.button>
                </div>
                <div class="lp-sheet__ladder" data-ui>
                    <p class="lp-sheet__ladder-head">Verification is earned with evidence, and shown exactly</p>
                    <ul>
                        <li><x-td.verify-badge status="verified" level="peer" /></li>
                        <li><x-td.verify-badge status="verified" level="independent" /></li>
                        <li><x-td.verify-badge status="verified" level="qualified" /></li>
                        <li><x-td.verify-badge status="verified" level="professional" /></li>
                        <li><x-td.verify-badge status="verified" level="institution" /></li>
                        <li><x-td.verify-badge status="self" level="qualified" /></li>
                    </ul>
                </div>
            </article>

            <article class="lp-sheet lp-sheet--guard" aria-labelledby="sheet-guard">
                <div class="lp-sheet__text">
                    <h3 id="sheet-guard" class="lp-sheet__title">Guardians stay in charge</h3>
                    <p>For learners under 18, a parent or guardian approves bookings, payments and rooms. Contact details stay private, and anything worrying can be reported from every page.</p>
                </div>
                <img class="lp-sheet__img" src="{{ asset('images/landing/south-african-library-1900.webp') }}" width="960" height="631" alt="The South African Library in Cape Town around 1900, behind its garden gates" loading="lazy" decoding="async">
            </article>
        </div>
    </section>

    {{-- Languages: the list itself, each language in its own name. --}}
    <section class="lp-section lp-langs" id="languages" aria-labelledby="langs-title">
        <div class="td-wrap lp-langs__grid">
            <div>
                <h2 id="langs-title" class="lp-h2">Learn in the language you think in</h2>
                <p class="lp-intro__text">Tutors list the languages they can teach in; you list the ones you understand. Matching uses both, and bilingual lessons count.</p>
                <p class="lp-footnote">Machine translation, where offered, is always labelled, and the original stays one tap away.</p>
            </div>
            <div class="lp-langsheet" data-ui>
                <p class="lp-langsheet__title">South Africa's official languages</p>
                <ul class="lp-langsheet__list">
                    @foreach ($languages as $language)
                        <li><span lang="{{ $language['code'] }}">{{ $language['own'] }}</span><span class="lp-langsheet__code" aria-hidden="true">{{ $language['code'] }}</span>@if ($language['own'] !== $language['name'])<span class="td-sr"> ({{ $language['name'] }})</span>@endif</li>
                    @endforeach
                </ul>
                <p class="lp-langsheet__title">Also ready</p>
                <ul class="lp-langsheet__list">
                    @foreach ($moreLanguages as $language)
                        <li><span lang="{{ $language['code'] }}">{{ $language['own'] }}</span><span class="lp-langsheet__code" aria-hidden="true">{{ $language['code'] }}</span><span class="td-sr"> ({{ $language['name'] }})</span></li>
                    @endforeach
                </ul>
            </div>
        </div>
    </section>

    {{-- Close: the shelf returns, with the two things a visitor came to do. --}}
    <section class="lp-close" aria-labelledby="close-title">
        <img class="lp-close__img" src="{{ asset('images/landing/book-spines.webp') }}" width="1000" height="667" alt="" loading="lazy" decoding="async">
        <div class="td-wrap lp-close__inner">
            <div class="lp-close__card">
                <h2 id="close-title" class="lp-h2">Bring the question you're stuck on</h2>
                <p>Sign in with InfoDot, say what you need, and see who can teach it.</p>
                <div class="lp-actions">
                    <x-td.button variant="primary" size="lg" :href="$findHref">Find a tutor</x-td.button>
                    <x-td.button size="lg" :href="$teachHref">Teach on Dot.Tutor</x-td.button>
                </div>
            </div>
        </div>
    </section>
</x-layouts::public>
