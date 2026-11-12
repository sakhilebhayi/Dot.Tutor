{{-- Ops restyle of the academic agent's teaching-language verification screen (same data: $rows). --}}
@php($state = request('state'))
<x-layouts::operations title="Tutors' teaching languages">
    <x-td.page-head title="Tutors' teaching languages" lede="Tutors declare the languages they can teach in and how well. Mark one verified only after you have checked it (an interview, a recorded lesson, a qualification). Verification is cleared automatically when the tutor changes the declared level." />
    <x-td.tabs class="mb-5" label="Filter" :items="[
        ['label' => 'All', 'href' => route('operator.academic.teaching-languages.index'), 'current' => ! $state],
        ['label' => 'Not verified', 'href' => route('operator.academic.teaching-languages.index', ['state' => 'unverified']), 'current' => $state === 'unverified'],
        ['label' => 'Verified', 'href' => route('operator.academic.teaching-languages.index', ['state' => 'verified']), 'current' => $state === 'verified'],
    ]" />
    @if (session('status'))<x-td.notice tone="success" class="mb-5" role="status">{{ session('status') }}</x-td.notice>@endif
    <x-td.panel flush>
        @if ($rows->isEmpty())
            <x-td.state kind="empty" title="No tutor has declared a teaching language yet" />
        @else
            <x-td.table caption="Teaching languages" caption-hidden dense>
                <thead><tr><th scope="col">Tutor</th><th scope="col">Language</th><th scope="col">Declared level</th><th scope="col">Verified</th><th scope="col"><span class="td-sr">Action</span></th></tr></thead>
                <tbody>
                    @foreach ($rows as $row)
                        <tr>
                            <td>{{ $row->user?->name ?? 'Deleted account' }}</td>
                            <td>{{ $row->language->name_en }}</td>
                            <td>{{ $row->proficiency?->label() }}</td>
                            <td>{{ $row->verified_at ? $row->verified_at->toDateString() : 'No' }}</td>
                            <td>
                                <form method="POST" action="{{ route('operator.academic.teaching-languages.'.($row->verified_at ? 'unverify' : 'verify'), $row) }}">
                                    @csrf
                                    <x-td.button type="submit" size="sm" :variant="$row->verified_at ? 'quiet' : 'primary'">{{ $row->verified_at ? 'Remove verification' : 'Mark verified' }}</x-td.button>
                                </form>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </x-td.table>
            @if ($rows->hasPages())<div class="px-5 py-3"><x-td.pagination :paginator="$rows" label="Pages" /></div>@endif
        @endif
    </x-td.panel>
</x-layouts::operations>
