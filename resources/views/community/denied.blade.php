{{-- A room the person may not enter, with the safeguarding reason in plain words. --}}
@php($codes = $decision->codes())
<x-layouts::learner title="This room is not open to you">
    <x-td.page-head title="This room is not open to you" :lede="$decision->message()" />
    <x-td.panel title="Why">
        <div class="grid gap-3" style="max-width: var(--td-measure)">
            @if (in_array('age_band_not_allowed', $codes, true))
                <p class="m-0">Rooms are kept separate for learners under 18 and for adults. Your own rooms for this subject are on My rooms.</p>
            @elseif (in_array('adult_screening_missing', $codes, true))
                <p class="m-0">Rooms for learners under 18 are open to adults only after a safeguarding screening that Dot.Tutor has recorded. Tutors can ask support about screening.</p>
            @elseif (in_array('guardian_consent_required', $codes, true))
                <p class="m-0">A parent or guardian needs to link to your account and give consent before you can join rooms.</p>
                @if (Route::has('safety.account'))<p class="m-0"><a class="td-link" href="{{ route('safety.account') }}">Invite a parent or guardian</a></p>@endif
            @endif
            <div><x-td.button :href="route('rooms.index')" variant="primary">Go to my rooms</x-td.button></div>
        </div>
    </x-td.panel>
</x-layouts::learner>
