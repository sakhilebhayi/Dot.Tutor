<x-layouts::workspace title="Students" step="dense">
    <x-td.page-head title="Students" :lede="$total === 0 ? 'Everyone who books you, takes a seat, buys a package or enrols in your courses appears here.' : $total.' '.\Illuminate\Support\Str::plural('student', $total).', '.$repeat.' of them with two or more completed lessons.'" />

    <form method="GET" action="{{ route('teach.students') }}" class="mb-4 flex flex-wrap items-end gap-3" role="search">
        <x-td.field label="Find a student" for="q"><x-dot.filter-search name="q" id="q" label="Find a student" placeholder="" :value="$search" /></x-td.field>
        <x-td.field label="Show" for="filter"><x-td.select name="filter" :options="['' => 'Everyone', 'repeat' => 'Returning students']" :value="$filter" /></x-td.field>
        <x-td.button type="submit" icon="search">Show</x-td.button>
    </form>

    <x-td.panel title="Your students" flush>
        @if ($rows->isEmpty())
            <div class="p-5">
                <x-td.state kind="empty" :title="$search || $filter ? 'No students match' : 'No students yet'">
                    {{ $search || $filter ? 'Try another name or show everyone.' : 'Your first student appears here after their first booking or enrolment. Share your storefront link to be found.' }}
                    @unless ($search || $filter)
                        <x-slot:actions><x-td.button href="{{ route('teach.storefront') }}" size="sm">Your storefront and links</x-td.button></x-slot:actions>
                    @endunless
                </x-td.state>
            </div>
        @else
            <x-td.table caption="Your students" caption-hidden dense stack>
                <thead><tr>
                    <th scope="col">Student</th><th scope="col" class="is-num">Lessons done</th><th scope="col" class="is-num">Coming up</th>
                    <th scope="col">Next lesson</th><th scope="col">Last lesson</th><th scope="col" class="is-num">Courses</th><th scope="col" data-stack="wide">Note</th>
                </tr></thead>
                <tbody>
                    @foreach ($rows as $r)
                        <tr>
                            <th scope="row"><a class="td-link" href="{{ route('teach.students.show', $r['id']) }}">{{ $r['name'] ?: 'Deleted account' }}</a></th>
                            <td class="is-num">{{ $r['completed'] }}</td>
                            <td class="is-num">{{ $r['upcoming'] }}</td>
                            <td>{{ $r['next'] ? $r['next']->copy()->timezone($tz)->format('D j M, H:i') : '—' }}</td>
                            <td>{{ $r['last'] ? $r['last']->copy()->timezone($tz)->format('j M Y') : '—' }}</td>
                            <td class="is-num">{{ $r['courses'] }}</td>
                            <td>{{ $r['has_note'] ? 'Yes' : '—' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </x-td.table>
        @endif
    </x-td.panel>
    <x-td.pagination :paginator="$rows" label="Student pages" class="mt-4" />
</x-layouts::workspace>
