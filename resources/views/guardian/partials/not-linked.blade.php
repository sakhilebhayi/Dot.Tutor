{{-- A signed-in person who is not (or no longer) anyone's guardian; a minor without a guardian can invite one. --}}
@php($viewerIsMinor = app(\App\Domain\Safety\Services\AccountStanding::class)->isMinor(request()->user()))
<div class="grid gap-6">
    <x-td.panel>
        <x-td.state kind="empty" title="You are not linked to a learner" icon="hand-heart">
            A learner invites their parent or guardian from their account. You get an email with a link; open it while signed in with that email address to give consent.
        </x-td.state>
    </x-td.panel>
    @if ($viewerIsMinor)
        <x-td.panel title="Invite your parent or guardian">
            <p class="mt-0">Until a parent or guardian gives consent, you can read, report and ask support for help, but not book, pay, message tutors or join rooms.</p>
            <form method="POST" action="{{ route('safety.guardians.invite') }}" class="grid gap-4">
                @csrf
                <x-td.field label="Their email address" for="g-email"><x-td.input name="email" id="g-email" type="email" required autocomplete="off" /></x-td.field>
                <x-td.field label="They are your" for="g-rel"><x-td.select name="relationship" id="g-rel" :options="['parent' => 'Parent', 'legal_guardian' => 'Legal guardian', 'caregiver' => 'Caregiver']" /></x-td.field>
                <div><x-td.button type="submit" variant="primary" size="lg">Send the invitation</x-td.button></div>
            </form>
        </x-td.panel>
    @endif
</div>
