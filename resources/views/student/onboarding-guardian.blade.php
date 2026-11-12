{{-- Onboarding, last step for a learner under 18 with no consenting guardian yet (D-1106). The invitation is the
     safety domain's (POST safety.guardians.invite); this screen only explains it in plain words. --}}
<x-student.shell title="Ask a parent or guardian">
    <x-td.page-head title="One more step: a parent or guardian"
        lede="Because you are under 18, a parent or guardian links to your account and says yes before you book lessons, pay or message tutors." />

    <div class="st-cols">
        <x-td.panel title="Send them an invitation">
            <form method="POST" action="{{ route('safety.guardians.invite') }}" class="st-form">
                @csrf
                <x-td.field label="Their email address" for="email" hint="They get a link that only works for this address. It stays valid for 3 days.">
                    <x-td.input type="email" name="email" required autocomplete="off" :hint="true" />
                </x-td.field>
                <x-td.field label="They are your" for="relationship">
                    <x-td.select name="relationship" :options="['parent' => 'Parent', 'legal_guardian' => 'Legal guardian', 'caregiver' => 'Caregiver']" />
                </x-td.field>
                <div class="st-form__actions">
                    <x-td.button type="submit" variant="primary" icon="hand-heart">Send the invitation</x-td.button>
                    <x-td.button variant="quiet" :href="route('learn.home')">Do this later</x-td.button>
                </div>
            </form>
        </x-td.panel>

        <x-td.panel title="What changes until they say yes">
            <ul class="st-stack st-stack--tight" style="margin:0;padding-left:1.1rem;list-style:disc">
                <li>You can look around, read the study library, practise and use free courses.</li>
                <li>Booking a lesson, paying and messaging a tutor wait for your parent or guardian.</li>
                <li>When they agree, they also see your lessons and purchases and approve the ones that need it.</li>
                <li>Your phone number and email are never shown to tutors.</li>
            </ul>
            @if ($guardians->isNotEmpty())
                <p style="margin:var(--td-space-4) 0 0" class="st-small">Already linked: {{ $guardians->count() }} {{ \Illuminate\Support\Str::plural('person', $guardians->count()) }}.</p>
            @endif
        </x-td.panel>
    </div>
</x-student.shell>
