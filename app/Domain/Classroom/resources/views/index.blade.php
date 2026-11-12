{{-- My live lessons: the next two weeks and the last week, each with its classroom or its record. --}}
@php
    $tz = config('app.timezone');
    $row = function ($s) use ($me) {
        $isTutor = $s->tutorProfile?->user_id === $me->id;

        return [
            'title' => ($s->subject?->name ?? 'Lesson').($s->isGroup() ? ' group lesson' : ''),
            'with' => $isTutor ? ($s->isGroup() ? 'Group' : ($s->student?->name ?? 'Learner')) : ($s->tutorProfile?->user?->name ?? 'Tutor'),
            'as' => $isTutor ? 'Teaching' : 'Learning',
        ];
    };
@endphp
<x-dynamic-component :component="'layouts::'.$shell" title="Live lessons">
    <x-td.page-head title="Live lessons" lede="Confirmed lessons in the next two weeks. The classroom opens {{ config('classroom.open_minutes_before') }} minutes before each one starts." />

    <x-td.panel title="Coming up" flush>
        @if ($upcoming->isEmpty())
            <x-td.state kind="empty" title="No confirmed lessons coming up">Lessons appear here once they are confirmed.</x-td.state>
        @else
            <x-td.table caption="Confirmed lessons coming up" caption-hidden>
                <thead><tr><th>When ({{ $tz }})</th><th>Lesson</th><th>With</th><th><span class="td-sr">You are</span></th><th><span class="td-sr">Classroom</span></th></tr></thead>
                <tbody>
                    @foreach ($upcoming as $s)
                        @php $r = $row($s); $open = now()->gte($access->opensAt($s)); @endphp
                        <tr>
                            <td><time datetime="{{ $s->starts_at->toIso8601String() }}">{{ $s->starts_at->copy()->timezone($tz)->format('D j M, H:i') }}</time></td>
                            <td>{{ $r['title'] }}</td>
                            <td>{{ $r['with'] }}</td>
                            <td class="cr-muted">{{ $r['as'] }}</td>
                            <td><x-td.button :href="route('classroom.show', $s)" size="sm" :variant="$open ? 'primary' : 'secondary'">{{ $open ? 'Open the classroom' : 'Check my camera' }}</x-td.button></td>
                        </tr>
                    @endforeach
                </tbody>
            </x-td.table>
        @endif
    </x-td.panel>

    @if ($past->isNotEmpty())
        <x-td.panel title="Last week" flush>
            <x-td.table caption="Lessons in the last week" caption-hidden>
                <thead><tr><th>When ({{ $tz }})</th><th>Lesson</th><th>With</th><th><span class="td-sr">Record</span></th></tr></thead>
                <tbody>
                    @foreach ($past as $s)
                        @php $r = $row($s); @endphp
                        <tr>
                            <td>{{ $s->starts_at->copy()->timezone($tz)->format('D j M, H:i') }}</td>
                            <td>{{ $r['title'] }}</td>
                            <td>{{ $r['with'] }}</td>
                            <td><a class="td-link" href="{{ route('classroom.after', $s) }}">Lesson record</a></td>
                        </tr>
                    @endforeach
                </tbody>
            </x-td.table>
        </x-td.panel>
    @endif
</x-dynamic-component>
