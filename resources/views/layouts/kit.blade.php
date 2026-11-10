{{-- The component kit on one page, for designers and wave-2 builders (docs/R47_DS.md). Rendered by
     tests/Feature/DesignSystemKitTest.php; it has no route of its own. Every value here is sample data and is
     labelled as such; nothing on this page is shown to learners. --}}
<x-layouts::learner title="Component kit" :step="$step ?? 'standard'">
    <x-td.page-head title="Component kit" lede="Every Dot.Tutor component in its states. Sample data only.">
        <x-slot:actions>
            <x-td.button variant="primary" icon="plus">Primary action</x-td.button>
            <x-td.button>Secondary</x-td.button>
        </x-slot:actions>
    </x-td.page-head>

    <div class="td-panels td-panels--2">
        <x-td.panel title="Buttons">
            <div class="flex flex-wrap gap-3">
                <x-td.button variant="primary">Book this lesson</x-td.button>
                <x-td.button>Save draft</x-td.button>
                <x-td.button variant="quiet" icon="pencil">Edit</x-td.button>
                <x-td.button variant="danger" icon="trash-2">Remove</x-td.button>
                <x-td.button variant="primary" loading>Paying</x-td.button>
                <x-td.button disabled>Not available</x-td.button>
                <x-td.button size="sm">Small</x-td.button>
                <x-td.button size="lg" variant="primary">Large</x-td.button>
                <x-td.button variant="quiet" icon="bell" icon-only>Notifications</x-td.button>
            </div>
        </x-td.panel>

        <x-td.panel title="Labels for people and levels">
            <div class="grid gap-3">
                <div class="flex flex-wrap gap-2">
                    <x-td.pill kind="stage" stage="foundation">Grade 2</x-td.pill>
                    <x-td.pill kind="stage" stage="intermediate">Grade 5</x-td.pill>
                    <x-td.pill kind="stage" stage="senior">Grade 8</x-td.pill>
                    <x-td.pill kind="stage" stage="fet">Grade 12</x-td.pill>
                    <x-td.pill kind="stage" stage="undergraduate">Second year</x-td.pill>
                    <x-td.pill kind="stage" stage="masters">Master's</x-td.pill>
                    <x-td.pill kind="stage" stage="doctoral">PhD</x-td.pill>
                    <x-td.pill kind="stage" stage="professional">Professional</x-td.pill>
                </div>
                <div class="flex flex-wrap gap-2">
                    <x-td.pill kind="level" stage="fet">Grade 11, CAPS</x-td.pill>
                    <x-td.pill>Physical sciences</x-td.pill>
                </div>
                <div class="flex flex-wrap gap-2">
                    <x-td.language-chip code="zu" name="Zulu" endonym="isiZulu" role="teaches" verified />
                    <x-td.language-chip code="af" name="Afrikaans" role="understands" />
                    <x-td.language-chip code="ve" name="Venda" endonym="Tshivenḓa" role="learning" />
                    <x-td.language-chip code="xh" name="Xhosa" endonym="isiXhosa" match />
                    <x-td.language-chip code="st" name="Southern Sotho" endonym="Sesotho" pressable pressed />
                </div>
                <div class="flex flex-wrap gap-2">
                    <x-td.verify-badge status="verified" level="qualified" />
                    <x-td.verify-badge status="verified" level="peer" />
                    <x-td.verify-badge status="review" level="professional" />
                    <x-td.verify-badge status="self" level="independent" />
                    <x-td.verify-badge status="lapsed" level="qualified" />
                </div>
                <div class="flex flex-wrap items-center gap-3">
                    <x-td.avatar name="Lerato Dlamini" decorative />
                    <x-td.avatar name="Pieter van Wyk" size="lg" />
                    <x-td.avatar name="Ayanda" size="sm" />
                    <x-td.money :minor="25000" per="hour" />
                    <x-td.money :minor="123456789" />
                    <x-td.money :minor="-5000" />
                </div>
            </div>
        </x-td.panel>

        <x-td.panel title="Fields">
            <form class="grid gap-5" action="#" method="get">
                <x-td.field label="What do you need help with?" for="kit-need" hint="Say it the way you would to a friend.">
                    <x-td.input name="kit-need" placeholder="Grade 11 Maths, in isiZulu, after school" :hint="true" />
                </x-td.field>
                <x-td.field label="Your grade" for="kit-grade">
                    <x-td.select name="kit-grade" placeholder="Choose a grade" :options="['10' => 'Grade 10', '11' => 'Grade 11', '12' => 'Grade 12']" />
                </x-td.field>
                <x-td.field label="Language you want to be taught in" for="kit-lang">
                    <x-td.combobox name="kit-lang" aria-label="Teaching language" :options="[
                        ['value' => 'zu', 'label' => 'isiZulu', 'hint' => 'Zulu', 'search' => 'zulu'],
                        ['value' => 'xh', 'label' => 'isiXhosa', 'hint' => 'Xhosa', 'search' => 'xhosa'],
                        ['value' => 'nso', 'label' => 'Sesotho sa Leboa', 'hint' => 'Sepedi', 'search' => 'sepedi northern sotho'],
                        ['value' => 'af', 'label' => 'Afrikaans'],
                        ['value' => 'en', 'label' => 'English'],
                    ]" />
                </x-td.field>
                <x-td.field label="A note for the tutor" for="kit-note" optional error="Keep it under 500 characters.">
                    <x-td.textarea name="kit-note" rows="3" aria-invalid="true" aria-describedby="kit-note-error" />
                </x-td.field>
                <x-td.check name="kit-bilingual" checked>I'm happy with lessons in two languages</x-td.check>
                <x-td.file-picker name="kit-file" label="Add study notes" accept=".pdf,.jpg,.png" :max-kb="10240" />
            </form>
        </x-td.panel>

        <x-td.panel title="Progress, timeline, notices">
            <div class="grid gap-5">
                <x-td.progress label="Grade 11 Algebra topics" :value="7" :max="12" display="7 of 12 topics" stage="fet" />
                <x-td.progress label="Course" :value="45" />
                <x-td.timeline label="Booking history (sample)">
                    <x-td.timeline-item title="Requested" state="done" meta="Monday 09:12" />
                    <x-td.timeline-item title="Guardian approved" state="done" meta="Monday 18:40" />
                    <x-td.timeline-item title="Payment" state="current" meta="Waiting for payment" />
                    <x-td.timeline-item title="Lesson" state="upcoming" meta="Thursday 16:00" />
                </x-td.timeline>
                <x-td.notice tone="info" title="Machine translation">This answer was translated from Afrikaans by a machine. The original is below it.</x-td.notice>
                <x-td.notice tone="warning">A guardian must approve this booking before it is confirmed.</x-td.notice>
                <x-td.notice tone="danger" title="Payment failed">The bank declined the card. Nothing was charged.</x-td.notice>
                <x-td.notice tone="success">Your request was sent to the tutor.</x-td.notice>
            </div>
        </x-td.panel>
    </div>

    <div class="mt-8 grid gap-6">
        <x-td.stat-strip label="Sample figures" :items="[
            ['label' => 'Lessons this month', 'value' => '12'],
            ['label' => 'Earned this month', 'value' => 'R3 480.00', 'hint' => 'After commission'],
            ['label' => 'Average reply time', 'value' => '—', 'hint' => 'Not enough requests yet'],
        ]" />

        <x-td.tabs label="Lessons" :items="[
            ['label' => 'Upcoming', 'href' => '#upcoming', 'current' => true, 'count' => 3],
            ['label' => 'Past', 'href' => '#past'],
            ['label' => 'Cancelled', 'href' => '#cancelled', 'count' => 0],
        ]" />

        <x-td.tabs label="Room" :items="[
            ['label' => 'Questions', 'panel' => 'kit-q'],
            ['label' => 'Notes', 'panel' => 'kit-n'],
        ]" />
        <x-td.tab-panel id="kit-q"><p>Questions panel (sample).</p></x-td.tab-panel>
        <x-td.tab-panel id="kit-n"><p>Notes panel (sample).</p></x-td.tab-panel>

        <x-td.panel title="Sample table" flush>
            <x-td.table caption="Sample lessons" caption-hidden>
                <thead><tr><th>Learner</th><th>Subject</th><th>When</th><th class="is-num">Price</th></tr></thead>
                <tbody>
                    <tr><td>Sample learner A</td><td>Mathematics</td><td class="is-muted">Thu 16:00</td><td class="is-num"><x-td.money :minor="22000" /></td></tr>
                    <tr><td>Sample learner B</td><td>Physical sciences</td><td class="is-muted">Fri 15:30</td><td class="is-num"><x-td.money :minor="25000" /></td></tr>
                </tbody>
            </x-td.table>
        </x-td.panel>

        <div class="td-panels td-panels--2">
            <x-td.state kind="empty" title="No lessons booked yet">
                Book a lesson and it will appear here with its time and link.
                <x-slot:actions><x-td.button variant="primary" href="#">Find a tutor</x-td.button></x-slot:actions>
            </x-td.state>
            <x-td.state kind="loading" title="Finding tutors who fit">
                <x-td.skeleton :lines="3" />
            </x-td.state>
            <x-td.state kind="success" title="Request sent">The tutor has 24 hours to reply.</x-td.state>
            <x-td.state kind="error" title="We couldn't load your lessons">
                Check your connection and try again.
                <x-slot:actions><x-td.button href="#">Try again</x-td.button></x-slot:actions>
            </x-td.state>
        </div>

        <x-td.pagination label="Sample pages" :paginator="new \Illuminate\Pagination\LengthAwarePaginator(range(1, 15), 87, 15, 3, ['path' => '/kit'])" />

        <x-td.match-reasons :reasons="[
            ['text' => 'Teaches Grade 11 Mathematics (CAPS)', 'met' => true],
            ['text' => 'Online only', 'met' => false],
        ]" />

        <div>
            <x-td.button data-td-dialog-open="kit-dialog">Open a dialog</x-td.button>
            <x-td.dialog id="kit-dialog" title="Cancel this lesson?" sheet>
                <p>The tutor will be told. Cancelling less than 24 hours before the lesson may not be refunded.</p>
                <x-slot:footer>
                    <x-td.button data-td-dialog-close>Keep it</x-td.button>
                    <x-td.button variant="danger">Cancel lesson</x-td.button>
                </x-slot:footer>
            </x-td.dialog>
        </div>
    </div>
</x-layouts::learner>
