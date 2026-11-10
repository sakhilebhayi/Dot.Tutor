{{-- Wave-1 functional operator view (English only, staff screen). --}}
<x-app-layout>
<div class="t-page">
    <p class="t-hint"><a href="{{ route('operator.academic.home') }}">Academic structure</a></p>
    <h1 class="t-h1">Tutors' teaching languages</h1>
    <p class="t-lede">
        Tutors declare the languages they can teach in and how well. Mark one verified only after you have checked it
        (an interview, a recorded lesson, a qualification). Verification is cleared automatically when the tutor changes the declared level.
    </p>
    @if(session('status'))<div class="t-flash" role="status">{{ session('status') }}</div>@endif

    <p class="t-hint">
        Show: <a href="{{ route('operator.academic.teaching-languages.index') }}">all</a> &middot;
        <a href="{{ route('operator.academic.teaching-languages.index', ['state' => 'unverified']) }}">not verified</a> &middot;
        <a href="{{ route('operator.academic.teaching-languages.index', ['state' => 'verified']) }}">verified</a>
    </p>

    <div class="t-panel table-scroll" style="padding:0;overflow-x:auto;" role="region" aria-label="Teaching languages" tabindex="0">
        <table class="t-table">
            <thead><tr><th>Tutor</th><th>Language</th><th>Declared level</th><th>Verified</th><th></th></tr></thead>
            <tbody>
            @forelse($rows as $row)
                <tr>
                    <td>{{ $row->user?->name ?? 'Deleted account' }}</td>
                    <td>{{ $row->language->name_en }}</td>
                    <td>{{ $row->proficiency?->label() }}</td>
                    <td>{{ $row->verified_at ? $row->verified_at->toDateString() : 'No' }}</td>
                    <td>
                        <form method="POST" action="{{ route('operator.academic.teaching-languages.'.($row->verified_at ? 'unverify' : 'verify'), $row) }}">
                            @csrf
                            <button type="submit" class="t-btn {{ $row->verified_at ? 't-btn-quiet' : 't-btn-primary' }}" style="padding:.35rem .7rem;">{{ $row->verified_at ? 'Remove verification' : 'Mark verified' }}</button>
                        </form>
                    </td>
                </tr>
            @empty
                <tr><td colspan="5" style="padding:2rem;">No tutor has declared a teaching language yet.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
    {{ $rows->links() }}
</div>
</x-app-layout>
