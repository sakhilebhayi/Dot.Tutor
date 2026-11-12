@php
    $statusWord = ['complete' => 'Fully translated', 'partial' => 'Partly translated', 'none' => 'Not translated yet, shown in English'];
@endphp
<x-student.shell title="Account and languages">
    <x-td.page-head title="Account and languages" lede="The languages you learn in, the language of this website, how screens look, and who looks after your account." />

    <div class="st-cols st-cols--even">
        <div class="st-stack">
            <x-td.panel title="Languages you learn in">
                <div class="st-stack">
                    @foreach ($roles as $label => $codes)
                        <div>
                            <p class="st-small" style="margin:0 0 var(--td-space-1)">{{ $label }}</p>
                            @if ($codes === [])
                                <p style="margin:0" class="st-muted">None chosen</p>
                            @else
                                <div class="st-chipset">
                                    @foreach ($codes as $code)
                                        <x-td.language-chip :code="$code" :name="$names[$code]->name_en ?? $code" :endonym="$names[$code]->name_native ?? null" />
                                    @endforeach
                                </div>
                            @endif
                        </div>
                    @endforeach
                    <p class="st-small" style="margin:0">Tutors are matched on the languages you want to be taught in. The language of this website never decides who you see.</p>
                    <div><x-td.button size="sm" icon="pencil" :href="route('academic.profile.edit')">Change languages and level</x-td.button></div>
                </div>
            </x-td.panel>

            <x-td.panel title="Language of this website">
                <form method="POST" action="{{ route('academic.locale.update') }}" class="st-form">
                    @csrf
                    <fieldset>
                        <legend class="td-sr">Language of this website</legend>
                        <div class="st-stack st-stack--tight">
                            @foreach ($localeOptions as $option)
                                <x-td.check type="radio" name="locale" :value="$option['code']" :checked="$currentLocale === $option['code']" id="locale-{{ $option['code'] }}">
                                    <span lang="{{ $option['code'] }}"><strong>{{ $option['name_native'] }}</strong></span>
                                    @if ($option['name_native'] !== $option['name_en'])<span>({{ $option['name_en'] }})</span>@endif
                                    <span class="st-small" style="display:block">{{ $statusWord[$option['status']] ?? $option['status'] }}@if ($option['provenance'] === 'machine_unreviewed') · drafted with AI, not yet checked by a native speaker @elseif ($option['provenance'] === 'reviewed') · checked by a native speaker @endif</span>
                                </x-td.check>
                            @endforeach
                        </div>
                    </fieldset>
                    <p class="st-small" style="margin:0">Only part of Dot.Tutor is translated so far. Words that are not translated yet stay in English. Course and study material keep the language they were written in.</p>
                    <div class="st-form__actions"><x-td.button type="submit" variant="primary">Use this language</x-td.button></div>
                </form>
            </x-td.panel>
        </div>

        <div class="st-stack">
            <x-td.panel title="How screens look">
                <form method="POST" action="{{ route('learn.account.step') }}" class="st-form">
                    @csrf @method('PUT')
                    <fieldset>
                        <legend class="td-sr">Screen size and detail</legend>
                        <div class="st-stack st-stack--tight">
                            <x-td.check type="radio" name="step" value="auto" :checked="$ctx['step_preference'] === 'auto'" id="step-auto">Follow my grade <span class="st-small" style="display:block">Larger and simpler for Grades 1 to 6, standard from Grade 7.</span></x-td.check>
                            <x-td.check type="radio" name="step" value="young" :checked="$ctx['step_preference'] === 'young'" id="step-young">Larger and simpler <span class="st-small" style="display:block">Bigger text and buttons, fewer things on each screen.</span></x-td.check>
                            <x-td.check type="radio" name="step" value="standard" :checked="$ctx['step_preference'] === 'standard'" id="step-standard">Standard</x-td.check>
                        </div>
                    </fieldset>
                    <p class="st-small" style="margin:0">Now: {{ $ctx['step'] === 'young' ? 'larger and simpler' : 'standard' }}. {{ $ctx['step_reason'] }}</p>
                    <div class="st-form__actions"><x-td.button type="submit">Save</x-td.button></div>
                </form>
                <div style="margin-top:var(--td-space-4)"><x-td.theme-switch /></div>
            </x-td.panel>

            <x-td.panel title="Safety and your parent or guardian">
                <dl class="st-facts">
                    <dt>Age group</dt>
                    <dd>{{ ['child' => 'Under 13', 'teen' => '13 to 17', 'adult' => '18 or older'][$standing['age_band']] ?? $standing['age_band'] }}{{ $standing['age_known'] ? '' : ' (no month of birth given yet)' }}</dd>
                    <dt>Account</dt>
                    <dd>{{ ['active' => 'Active', 'limited' => 'Waiting for a parent or guardian', 'suspended' => 'Paused by an operator'][$standing['status']] ?? $standing['status'] }}</dd>
                    @if ($standing['is_minor'])
                        <dt>Parent or guardian</dt>
                        <dd>
                            @if ($guardians->isEmpty()) Nobody linked yet @else {{ $guardians->pluck('name')->implode(', ') }} @endif
                            @foreach ($invitations as $invitation)<br><span class="st-small">Invitation sent to {{ $invitation->email }}, waiting for them</span>@endforeach
                        </dd>
                    @endif
                </dl>
                <div class="st-inline" style="margin-top:var(--td-space-3)">
                    <x-td.button size="sm" icon="shield-check" :href="route('safety.account')">{{ $standing['is_minor'] ? 'Invite or manage' : 'Safety settings' }}</x-td.button>
                </div>
            </x-td.panel>

            <x-td.panel title="Elsewhere">
                <ul class="st-list">
                    <li class="st-row"><div><p class="st-row__title"><a href="{{ route('money.orders') }}">Purchases and receipts</a></p></div></li>
                    @if (Route::has('comms.preferences'))<li class="st-row"><div><p class="st-row__title"><a href="{{ route('comms.preferences') }}">Notification settings</a></p><p class="st-row__meta">Email and phone alerts for lessons and payments.</p></div></li>@endif
                    @if (Route::has('comms.inbox'))<li class="st-row"><div><p class="st-row__title"><a href="{{ route('comms.inbox') }}">Notifications</a></p></div></li>@endif
                    <li class="st-row"><div><p class="st-row__title"><a href="{{ route('profile.show') }}">Sign-in and personal details</a></p><p class="st-row__meta">Your InfoDot account: name, email and password.</p></div></li>
                    @if (Route::has('security.privacy'))<li class="st-row"><div><p class="st-row__title"><a href="{{ route('security.privacy') }}">Your data and account</a></p><p class="st-row__meta">Download everything held about you, or close the account.</p></div></li>@endif
                </ul>
            </x-td.panel>
        </div>
    </div>
</x-student.shell>
