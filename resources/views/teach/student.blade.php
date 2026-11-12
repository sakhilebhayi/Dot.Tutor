<x-layouts::workspace :title="$learner->name ?: 'Student'">
    <x-td.page-head :title="$learner->name ?: 'Deleted account'" lede="What this student did with you on Dot.Tutor. Their other tutors, courses and personal details are not shown.">
        <x-slot:actions><x-td.button href="{{ route('teach.students') }}" icon="arrow-left">All students</x-td.button></x-slot:actions>
    </x-td.page-head>

    <div class="td-panels td-panels--2">
        <x-td.panel title="Lessons" flush>
            @if ($sessions->isEmpty())
                <div class="p-5"><p class="m-0 text-td-ink-2">No one-to-one lessons yet. This student came through a group seat, a package or a course.</p></div>
            @else
                <x-td.table caption="Lessons with this student" caption-hidden dense>
                    <thead><tr><th scope="col">When</th><th scope="col">Subject</th><th scope="col">Offering</th><th scope="col">State</th></tr></thead>
                    <tbody>
                        @foreach ($sessions as $s)
                            <tr>
                                <td class="tabular-nums">{{ $s->starts_at->copy()->timezone($tz)->format('j M Y, H:i') }}</td>
                                <td>{{ $s->subject?->name }}</td>
                                <td>{{ $s->offering?->title ?? 'Lesson' }}</td>
                                <td>{{ \App\Enums\SessionStatus::from($s->status)->label() }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </x-td.table>
            @endif
        </x-td.panel>

        <x-td.panel title="Your private note">
            <form method="POST" action="{{ route('teach.students.note', $learner->id) }}" class="grid gap-3">
                @csrf @method('PUT')
                <x-td.field label="Note" for="body" hint="Only you can read this. Keep it to teaching: what you covered, what to try next. Do not record health, family or contact details.">
                    <x-td.textarea name="body" :value="$note?->body" rows="6" :hint="true" maxlength="5000" />
                </x-td.field>
                <div><x-td.button type="submit" variant="primary">Save note</x-td.button></div>
                @if ($note)<p class="m-0 text-sm text-td-ink-2">Last saved {{ $note->updated_at->timezone($tz)->format('j M Y, H:i') }}.</p>@endif
            </form>
        </x-td.panel>
    </div>

    <div class="td-panels mt-6 md:grid-cols-2">
        <x-td.panel title="Progress in your courses">
            @forelse ($courses as $c)
                <x-td.progress :label="$c['title'] ?? 'Course'" :value="$c['summary']['completed']" :max="max(1, $c['summary']['total'])" :display="$c['summary']['completed'].' of '.$c['summary']['total'].' lessons'" class="mb-4" />
            @empty
                <p class="m-0 text-td-ink-2">Not enrolled in any of your courses.</p>
            @endforelse
        </x-td.panel>
        <x-td.panel title="Assessments you assigned">
            @forelse ($assignments as $a)
                <p class="m-0 flex flex-wrap justify-between gap-2 border-t border-td-line py-2 first:border-t-0">
                    <span>{{ $a['title'] }}</span>
                    <span class="tabular-nums">{{ $a['attempted'] ? 'Best '.$a['best'].'%'.($a['passed'] ? ', passed' : ', not passed yet') : 'Not attempted' }}</span>
                </p>
            @empty
                <p class="m-0 text-td-ink-2">No assessments assigned. <a class="td-link" href="{{ route('teach.materials.assessments') }}">Assign one</a> to see marked results here.</p>
            @endforelse
        </x-td.panel>
    </div>

    @if ($packages->isNotEmpty())
        <x-td.panel title="Packages" class="mt-6">
            @foreach ($packages as $p)
                <p class="m-0">{{ $p->offering?->title }}: {{ $p->lessons_used }} of {{ $p->lessons_total }} lessons used, {{ str_replace('_', ' ', $p->status) }}.</p>
            @endforeach
        </x-td.panel>
    @endif
</x-layouts::workspace>
