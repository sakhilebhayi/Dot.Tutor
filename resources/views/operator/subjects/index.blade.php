<x-app-layout>
<div class="t-page">
    <h1 class="t-h1">Subject catalogue</h1>
    <p class="t-lede">
        Tutors choose what they teach from this list. Deactivating a subject hides it from new applications
        and new bookings; sessions that already used it keep it. Subjects are never deleted.
    </p>

    @if(session('status'))<div class="t-flash" role="status">{{ session('status') }}</div>@endif

    <div style="display:grid;grid-template-columns:1.6fr 1fr;gap:1rem;align-items:start;">
        <div class="t-panel" style="padding:0;overflow-x:auto;">
            <table class="t-table">
                <thead><tr><th>Subject</th><th>Level</th><th style="text-align:right;">Tutors</th><th>State</th><th></th></tr></thead>
                <tbody>
                @forelse($subjects as $subject)
                    <tr>
                        <td>
                            <form method="POST" action="{{ route('operator.subjects.update', $subject) }}" id="edit-{{ $subject->id }}">@csrf @method('PUT')</form>
                            <label for="name-{{ $subject->id }}" style="position:absolute;left:-9999px;">Name of {{ $subject->name }}</label>
                            <input class="t-field" form="edit-{{ $subject->id }}" id="name-{{ $subject->id }}" name="name" value="{{ $subject->name }}" maxlength="120" required style="min-width:10rem;">
                        </td>
                        <td>
                            <label for="level-{{ $subject->id }}" style="position:absolute;left:-9999px;">Level of {{ $subject->name }}</label>
                            <select class="t-field" form="edit-{{ $subject->id }}" id="level-{{ $subject->id }}" name="level">
                                @foreach($levels as $key => $label)
                                    <option value="{{ $key }}" @selected($subject->level === $key)>{{ $label }}</option>
                                @endforeach
                                @unless(array_key_exists($subject->level, $levels))
                                    <option value="{{ $subject->level }}" selected>{{ $subject->level }}</option>
                                @endunless
                            </select>
                        </td>
                        <td class="t-mono" style="text-align:right;">{{ $subject->tutors_count }}</td>
                        <td><span class="t-state {{ $subject->is_active ? 't-state-approved' : '' }}">{{ $subject->is_active ? 'Active' : 'Hidden' }}</span></td>
                        <td style="white-space:nowrap;text-align:right;">
                            <button type="submit" form="edit-{{ $subject->id }}" class="t-btn t-btn-quiet" style="padding:.35rem .7rem;">Save</button>
                            <form method="POST" action="{{ route('operator.subjects.'.($subject->is_active ? 'deactivate' : 'activate'), $subject) }}" style="display:inline;">
                                @csrf
                                <button type="submit" class="t-btn {{ $subject->is_active ? 't-btn-danger' : 't-btn-quiet' }}" style="padding:.35rem .7rem;">{{ $subject->is_active ? 'Deactivate' : 'Reactivate' }}</button>
                            </form>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="5" style="padding:2rem;color:#a1a1aa;">
                        The catalogue is empty, so nobody can apply to teach yet. Add the first subject on the right,
                        or run <span class="t-mono">php artisan db:seed --class=SubjectCatalogueSeeder</span> for a starter list.
                    </td></tr>
                @endforelse
                </tbody>
            </table>
        </div>

        <form method="POST" action="{{ route('operator.subjects.store') }}" class="t-panel">
            @csrf
            <h2 class="t-h2">Add a subject</h2>
            <div class="t-row">
                <label class="t-label" for="new-name">Name</label>
                <input class="t-field" id="new-name" name="name" maxlength="120" required value="{{ old('name') }}">
                @error('name')<div class="t-err">{{ $message }}</div>@enderror
            </div>
            <div class="t-row">
                <label class="t-label" for="new-level">Level</label>
                <select class="t-field" id="new-level" name="level">
                    @foreach($levels as $key => $label)
                        <option value="{{ $key }}" @selected(old('level', 'high_school') === $key)>{{ $label }}</option>
                    @endforeach
                </select>
                @error('level')<div class="t-err">{{ $message }}</div>@enderror
            </div>
            <button type="submit" class="t-btn t-btn-primary">Add subject</button>
        </form>
    </div>
</div>
</x-app-layout>
