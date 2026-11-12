@php
    $approved = $state['key'] === 'approved';
    $title = $approved ? 'Profile and verification' : 'Become a tutor';
    $current = collect($steps)->firstWhere('key', $step);
    $rate = $profile?->hourly_rate_minor ? number_format($profile->hourly_rate_minor / 100, 2, '.', '') : '';
    $scopeRows = $scopes->map(fn ($s) => ['subject_id' => $s->subject_id, 'level_min_id' => $s->level_min_id, 'level_max_id' => $s->level_max_id, 'notes' => $s->notes])->all();
    $scopeRows = array_pad($scopeRows, max(3, count($scopeRows) + 2), ['subject_id' => null, 'level_min_id' => null, 'level_max_id' => null, 'notes' => null]);
    $langRows = collect($teaching)->map(fn ($l) => ['code' => $l->code, 'proficiency' => $l->proficiency->value, 'verified' => $l->verified])->all();
    $langRows = array_pad($langRows, max(3, count($langRows) + 1), ['code' => null, 'proficiency' => 'fluent', 'verified' => false]);
    $languageOptions = collect($allLanguages)->map(fn ($l) => ['value' => $l['code'], 'label' => $l['endonym'] === $l['name'] ? $l['name'] : $l['endonym'].' ('.$l['name'].')'])->all();
    $proficiencyLabels = ['working' => 'Working: I can explain most things', 'fluent' => 'Fluent: I explain everything comfortably', 'native' => 'First language'];
    $kindOptions = collect($kinds)->mapWithKeys(fn ($k) => [$k->value => $k->label()])->all();
    $ladderShort = ['peer_student_tutor' => 'Peer student tutor', 'independent_tutor' => 'Independent tutor', 'verified_qualified_tutor' => 'Qualified tutor', 'professional_educator' => 'Professional educator', 'institution_affiliated' => 'Institution-affiliated'];
    $statusTone = ['verified' => 'verified', 'submitted' => 'review', 'rejected' => 'rejected', 'unverified' => 'self'];
@endphp
<x-layouts::workspace :title="$title">
    <x-td.page-head :title="$title" :lede="$approved ? 'What learners see about you, and what Dot.Tutor has checked.' : 'Set up your tutoring profile step by step. It stays a draft until you send it for review.'">
        <x-slot:actions>
            @if ($profile)
                <x-td.button href="{{ route('teach.storefront') }}" icon="eye">Storefront</x-td.button>
            @endif
        </x-slot:actions>
    </x-td.page-head>

    {{-- The application's honest state. --}}
    @if ($profile)
        <div class="mb-6 grid gap-3">
            @switch($state['key'])
                @case('draft')
                    <x-td.notice title="Draft, not sent">Only you can see this profile. Finish the required steps, then send it for review.</x-td.notice>
                    @break
                @case('review')
                    <x-td.notice tone="info" title="Sent, under review">An operator is reviewing your profile{{ $onboarding?->submitted_at ? ' (sent '.$onboarding->submitted_at->timezone($profile->scheduleTimezone())->format('j M Y, H:i').')' : '' }}. You can keep editing; changes are part of the review.</x-td.notice>
                    @break
                @case('approved')
                    <x-td.notice tone="success" title="Approved">Learners can find and book you. Verification levels are separate: they come only from reviewed evidence.</x-td.notice>
                    @break
                @case('rejected')
                    <x-td.notice tone="danger" title="Not approved">
                        <p>Reason given by the reviewer: {{ $profile->decisionReason() ?: 'no reason was recorded.' }}</p>
                        <p>Change your profile to answer it, then send it again from the last step.</p>
                    </x-td.notice>
                    @break
                @case('suspended')
                    <x-td.notice tone="warning" title="Suspended">
                        <p>Reason: {{ $profile->decisionReason() ?: 'no reason was recorded.' }}</p>
                        <p>Learners cannot book you while suspended. You can answer the reason and send the profile again.</p>
                    </x-td.notice>
                    @break
            @endswitch
        </div>
    @endif

    <div class="grid gap-6 lg:grid-cols-[17rem_minmax(0,1fr)] lg:items-start">
        <nav aria-label="Onboarding steps">
            <x-td.timeline label="Steps to become a tutor">
                @foreach ($steps as $s)
                    @php $itemState = $s['key'] === $step ? 'current' : ($s['done'] ? 'done' : 'upcoming'); @endphp
                    <x-td.timeline-item :title="$s['title']" :state="$itemState" :meta="$s['required'] ? null : 'Optional'">
                        @if ($profile || $s['key'] === 'about')
                            <a class="td-link" href="{{ route('teach.profile', ['step' => $s['key']]) }}" @if ($s['key'] === $step) aria-current="page" @endif>{{ $s['key'] === $step ? 'You are here' : ($s['done'] ? 'Review' : 'Open') }}<span class="td-sr"> {{ $s['title'] }}</span></a>
                        @endif
                    </x-td.timeline-item>
                @endforeach
            </x-td.timeline>
        </nav>

        <div class="grid gap-6">
            <x-td.panel :title="$current['title']" :heading-level="2">
                <p class="mb-5 text-td-ink-2">{{ $current['summary'] }}</p>

                @switch($step)
                    @case('about')
                        <form method="POST" action="{{ $profile ? route('teach.profile.about') : route('teach.profile.start') }}" class="grid gap-5">
                            @csrf
                            @if ($profile) @method('PUT') @endif
                            <x-td.field label="Headline" for="headline" hint="One line learners read first. For example: Grade 10 to 12 Maths and Physical Sciences, CAPS and IEB.">
                                <x-td.input name="headline" :value="$profile?->headline" maxlength="160" required :hint="true" />
                            </x-td.field>
                            <x-td.field label="Introduction" for="introduction" hint="Who you are and who you help. Do not put phone numbers, emails or links here: contact stays on Dot.Tutor.">
                                <x-td.textarea name="introduction" :value="$profile?->bio" rows="6" required :hint="true" />
                            </x-td.field>
                            <x-td.field label="How you teach" for="teaching_approach" optional hint="How a lesson with you goes, and how you check that a learner understood.">
                                <x-td.textarea name="teaching_approach" :value="$profile?->teaching_approach" rows="4" :hint="true" />
                            </x-td.field>
                            <div class="grid gap-5 md:grid-cols-[minmax(0,1fr)_12rem]">
                                <x-td.field label="Teaching experience" for="experience_summary" optional>
                                    <x-td.textarea name="experience_summary" :value="$profile?->experience_summary" rows="3" />
                                </x-td.field>
                                <x-td.field label="Years tutoring" for="years_experience" optional>
                                    <x-td.input name="years_experience" type="number" min="0" max="70" :value="$profile?->years_experience" />
                                </x-td.field>
                            </div>
                            <fieldset class="grid gap-3">
                                <legend class="td-label">Where you teach</legend>
                                <input type="hidden" name="teaches_online" value="0">
                                <x-td.check name="teaches_online" :checked="$profile ? $profile->teaches_online : true">Online, in the Dot.Tutor classroom</x-td.check>
                                <input type="hidden" name="teaches_in_person" value="0">
                                <x-td.check name="teaches_in_person" :checked="(bool) $profile?->teaches_in_person">In person</x-td.check>
                                <x-td.field label="Area for in-person lessons" for="in_person_area" optional hint="A suburb or town, never a street address.">
                                    <x-td.input name="in_person_area" :value="$profile?->in_person_area" maxlength="120" :hint="true" />
                                </x-td.field>
                            </fieldset>
                            <div class="grid gap-5 md:grid-cols-2">
                                <x-td.field label="Usual hourly rate ({{ $currency }})" for="hourly_rate" hint="Your starting price. Each offering sets its own price later.">
                                    <x-td.input name="hourly_rate" inputmode="decimal" :value="$rate" required :hint="true" />
                                </x-td.field>
                                <x-td.field label="Your time zone" for="timezone">
                                    <x-td.select name="timezone" :options="array_combine($timezones, $timezones)" :value="$profile?->timezone ?: $defaultTimezone" />
                                </x-td.field>
                            </div>
                            <div><x-td.button type="submit" variant="primary">{{ $profile ? 'Save and continue' : 'Save draft and continue' }}</x-td.button></div>
                        </form>
                        @break

                    @case('photo')
                        <div class="grid gap-5 md:grid-cols-[8rem_minmax(0,1fr)] md:items-start">
                            <x-td.avatar :name="auth()->user()->name" :src="$profile->photo_path ? route('marketplace.tutors.photo', $profile).'?v='.optional($profile->photo_updated_at)->timestamp : null" size="xl" />
                            <div class="grid gap-4">
                                <form method="POST" action="{{ route('teach.profile.photo') }}" enctype="multipart/form-data" class="grid gap-4" data-teach-upload>
                                    @csrf
                                    <x-td.file-picker name="photo" label="Choose a photo" accept="image/jpeg,image/png,image/webp" :max-kb="(int) config('marketplace.photo_max_kb')" hint="JPEG, PNG or WebP. It is resized and re-saved, which removes location and camera details." />
                                    <div class="flex flex-wrap gap-3">
                                        <x-td.button type="submit" variant="primary">Upload photo</x-td.button>
                                        <x-td.button href="{{ route('teach.profile', ['step' => 'scope']) }}" variant="quiet">Skip for now</x-td.button>
                                    </div>
                                </form>
                                @if ($profile->photo_path)
                                    <form method="POST" action="{{ route('teach.profile.photo.destroy') }}">
                                        @csrf @method('DELETE')
                                        <x-td.button type="submit" variant="quiet" icon="trash-2" size="sm">Remove photo</x-td.button>
                                    </form>
                                @endif
                                <p class="text-sm text-td-ink-2">Learners see your photo only after your profile is approved.</p>
                            </div>
                        </div>
                        @break

                    @case('scope')
                        @if ($levels === [])
                            <x-td.notice tone="warning" title="Curricula and levels are not loaded on this server">You can still choose subjects. Levels appear once an operator loads the academic reference data.</x-td.notice>
                        @endif
                        <form method="POST" action="{{ route('teach.profile.scope') }}" class="grid gap-4">
                            @csrf @method('PUT')
                            <p class="text-sm text-td-ink-2">One line per subject. Pick the lowest and highest level you teach; both come from the same curriculum (for example CAPS Grade 8 to CAPS Grade 12).</p>
                            @error('scopes')<x-td.notice tone="danger">{{ $message }}</x-td.notice>@enderror
                            @foreach ($errors->getMessages() as $key => $messages)
                                @if (str_starts_with($key, 'scopes.'))<x-td.notice tone="danger">Line {{ ((int) explode('.', $key)[1]) + 1 }}: {{ $messages[0] }}</x-td.notice>@endif
                            @endforeach
                            @foreach ($scopeRows as $i => $row)
                                <fieldset class="grid gap-3 border-t border-td-line pt-4 md:grid-cols-3">
                                    <legend class="td-sr">Subject line {{ $i + 1 }}</legend>
                                    <x-td.field label="Subject" for="scope-{{ $i }}-subject" :optional="$i > 0">
                                        <x-td.select name="scopes[{{ $i }}][subject_id]" id="scope-{{ $i }}-subject" :options="$subjects" :value="$row['subject_id']" placeholder="Choose a subject" />
                                    </x-td.field>
                                    <x-td.field label="From level" for="scope-{{ $i }}-min" optional>
                                        <x-td.select name="scopes[{{ $i }}][level_min_id]" id="scope-{{ $i }}-min" :options="$levels" :value="$row['level_min_id']" placeholder="Any level" />
                                    </x-td.field>
                                    <x-td.field label="To level" for="scope-{{ $i }}-max" optional>
                                        <x-td.select name="scopes[{{ $i }}][level_max_id]" id="scope-{{ $i }}-max" :options="$levels" :value="$row['level_max_id']" placeholder="Same as from" />
                                    </x-td.field>
                                </fieldset>
                            @endforeach
                            <div class="flex flex-wrap gap-3">
                                <x-td.button type="submit" variant="primary">Save and continue</x-td.button>
                                <x-td.button type="submit" name="stay" value="1">Save and add more lines</x-td.button>
                            </div>
                        </form>
                        @break

                    @case('languages')
                        <form method="POST" action="{{ route('teach.profile.languages') }}" class="grid gap-4">
                            @csrf @method('PUT')
                            <p class="text-sm text-td-ink-2">Matching uses these, never the language the app is shown in. A learner who asks for isiZulu sees tutors who teach in isiZulu. Your level is self-declared until an operator checks it; changing a language or level drops an earlier check.</p>
                            @error('languages')<x-td.notice tone="danger">{{ $message }}</x-td.notice>@enderror
                            @foreach ($langRows as $i => $row)
                                <fieldset class="grid gap-3 border-t border-td-line pt-4 md:grid-cols-2">
                                    <legend class="td-sr">Language {{ $i + 1 }}</legend>
                                    <x-td.field label="Language" for="lang-{{ $i }}-code" :optional="$i > 0">
                                        <x-td.select name="languages[{{ $i }}][code]" id="lang-{{ $i }}-code" :options="$languageOptions" :value="$row['code']" placeholder="Choose a language" />
                                    </x-td.field>
                                    <x-td.field label="How well you teach in it" for="lang-{{ $i }}-level" :hint="$row['verified'] ? 'Checked by Dot.Tutor. Changing the level drops the check.' : null">
                                        <x-td.select name="languages[{{ $i }}][proficiency]" id="lang-{{ $i }}-level" :options="$proficiencyLabels" :value="$row['proficiency']" />
                                    </x-td.field>
                                </fieldset>
                            @endforeach
                            @if ($teaching !== [])
                                <div class="flex flex-wrap gap-2" aria-label="Declared now">
                                    @foreach ($teaching as $l)
                                        @php $meta = collect($allLanguages)->firstWhere('code', $l->code); @endphp
                                        <x-td.language-chip :code="$l->code" :name="$l->nameEn" :endonym="$meta['endonym'] ?? null" role="teaches" :verified="$l->verified" />
                                    @endforeach
                                </div>
                            @endif
                            <div class="flex flex-wrap gap-3">
                                <x-td.button type="submit" variant="primary">Save and continue</x-td.button>
                                <x-td.button type="submit" name="stay" value="1">Save and add another</x-td.button>
                            </div>
                        </form>
                        @break

                    @case('qualifications')
                        <div class="grid gap-6">
                            <p class="text-sm text-td-ink-2">Only qualifications an operator has reviewed show as verified. Everything else shows to learners as self-declared. Evidence files are private: only you and the reviewing operator can open them. There is no virus scanner on this server, so send only files you made or received yourself.</p>
                            @forelse ($qualifications as $q)
                                <article class="grid gap-3 border-t border-td-line pt-4">
                                    <div class="flex flex-wrap items-start justify-between gap-3">
                                        <div>
                                            <h3 class="font-bold">{{ $q->title }}</h3>
                                            <p class="text-sm text-td-ink-2">{{ $q->kindEnum()->label() }}{{ $q->institution ? ', '.$q->institution : '' }}{{ $q->year_awarded ? ', '.$q->year_awarded : '' }}</p>
                                        </div>
                                        @include('teach.partials.status', ['tone' => $statusTone[$q->status] ?? 'self', 'text' => $q->statusEnum()->publicLabel()])
                                    </div>
                                    @if ($q->status === 'rejected' && $q->review_note)
                                        <p class="text-sm">Reviewer's note: {{ $q->review_note }}</p>
                                    @endif
                                    @if ($q->evidence->isNotEmpty())
                                        <ul class="grid gap-1 text-sm">
                                            @foreach ($q->evidence as $e)
                                                <li class="flex flex-wrap items-center gap-3">
                                                    <a class="td-link" href="{{ route('marketplace.api.me.evidence.show', $e) }}">{{ $e->original_name }}</a>
                                                    <span class="text-td-ink-2">{{ number_format(($e->size_bytes ?? 0) / 1024) }} KB</span>
                                                    @if (! in_array($q->status, ['verified'], true))
                                                        <form method="POST" action="{{ route('teach.profile.evidence.destroy', $e) }}">@csrf @method('DELETE')<x-td.button type="submit" variant="quiet" size="sm">Remove<span class="td-sr"> {{ $e->original_name }}</span></x-td.button></form>
                                                    @endif
                                                </li>
                                            @endforeach
                                        </ul>
                                    @endif
                                    @if ($q->status !== 'verified')
                                        <div class="flex flex-wrap items-end gap-3">
                                            <form method="POST" action="{{ route('teach.profile.evidence.store', $q) }}" enctype="multipart/form-data" class="flex flex-wrap items-end gap-3" data-teach-upload>
                                                @csrf
                                                <x-td.file-picker name="file" id="evidence-{{ $q->id }}" label="Add evidence" accept="application/pdf,image/jpeg,image/png" :max-kb="(int) config('marketplace.evidence_max_kb')" />
                                                <x-td.button type="submit" size="sm">Attach</x-td.button>
                                            </form>
                                            @if ($q->status !== 'submitted' && $q->evidence->isNotEmpty())
                                                <form method="POST" action="{{ route('teach.profile.qualifications.submit', $q) }}">@csrf<x-td.button type="submit" variant="primary" size="sm">Send for review</x-td.button></form>
                                            @endif
                                            <form method="POST" action="{{ route('teach.profile.qualifications.destroy', $q) }}">@csrf @method('DELETE')<x-td.button type="submit" variant="quiet" size="sm" icon="trash-2">Delete<span class="td-sr"> {{ $q->title }}</span></x-td.button></form>
                                        </div>
                                    @endif
                                </article>
                            @empty
                                <x-td.state kind="empty" title="No qualifications recorded yet">A student tutoring peers can start with proof of current enrolment. Experience, degrees and teaching registration lead to higher levels.</x-td.state>
                            @endforelse

                            <form method="POST" action="{{ route('teach.profile.qualifications.store') }}" enctype="multipart/form-data" class="grid gap-4 border-t border-td-line pt-5" data-teach-upload>
                                @csrf
                                <h3 class="font-bold">Add a qualification or proof</h3>
                                <div class="grid gap-4 md:grid-cols-2">
                                    <x-td.field label="What it is" for="kind"><x-td.select name="kind" :options="$kindOptions" /></x-td.field>
                                    <x-td.field label="Title" for="title" hint="For example: BSc Mathematics, or Proof of registration 2026."><x-td.input name="title" maxlength="200" required :hint="true" /></x-td.field>
                                    <x-td.field label="Institution or issuer" for="institution" optional><x-td.input name="institution" maxlength="200" /></x-td.field>
                                    <x-td.field label="Year" for="year_awarded" optional><x-td.input name="year_awarded" type="number" min="1950" max="{{ date('Y') + 1 }}" /></x-td.field>
                                </div>
                                <x-td.file-picker name="file" id="new-evidence" label="Evidence (optional now)" accept="application/pdf,image/jpeg,image/png" :max-kb="(int) config('marketplace.evidence_max_kb')" hint="PDF, JPEG or PNG. Up to five files per qualification." />
                                <div><x-td.button type="submit" variant="primary">Record qualification</x-td.button></div>
                            </form>
                        </div>
                        @break

                    @case('safeguarding')
                        <form method="POST" action="{{ route('teach.profile.safeguarding') }}" class="grid gap-5">
                            @csrf @method('PUT')
                            <fieldset class="grid gap-3">
                                <legend class="td-label">Will you teach learners under 18?</legend>
                                <x-td.check type="radio" name="teaches_minors" value="0" :checked="$onboarding && ! $onboarding->teaches_minors">No, adults only for now</x-td.check>
                                <x-td.check type="radio" name="teaches_minors" value="1" :checked="(bool) $onboarding?->teaches_minors">Yes, I want to teach school learners</x-td.check>
                            </fieldset>
                            <div><x-td.button type="submit" variant="primary">Save and continue</x-td.button></div>
                        </form>
                        <div class="mt-6 grid gap-3">
                            <h3 class="font-bold">Screening for work with children</h3>
                            <p class="text-sm text-td-ink-2">Before you can book, message or meet anyone under 18, Dot.Tutor operators record a clearance from the registers below, checked outside the platform. Nothing is checked automatically, and you cannot upload these yourself: an operator will contact you about what they need after you send your profile.</p>
                            <ul class="grid gap-2">
                                @foreach ((array) config('safety.screening.required_kinds') as $kind)
                                    @php $missing = in_array($kind, $screeningMissing, true); @endphp
                                    <li class="flex flex-wrap items-center justify-between gap-3 border-t border-td-line pt-2">
                                        <span>{{ \App\Domain\Safety\Enums\ScreeningKind::tryFrom($kind)?->label() ?? str_replace('_', ' ', ucfirst($kind)) }}</span>
                                        @include('teach.partials.status', ['tone' => $missing ? 'self' : 'verified', 'text' => $missing ? 'Not recorded' : 'Cleared and current'])
                                    </li>
                                @endforeach
                            </ul>
                            <p class="text-sm text-td-ink-2">Without these, the platform refuses lessons with learners under 18 on the server, whatever this page shows.</p>
                        </div>
                        @break

                    @case('availability')
                        @if ($windows->isEmpty())
                            <x-td.state kind="empty" title="No weekly hours yet">Learners can only request times inside your open hours.
                                <x-slot:actions><x-td.button href="{{ route('tutor.availability.index') }}" variant="primary" icon="calendar">Set weekly hours</x-td.button></x-slot:actions>
                            </x-td.state>
                        @else
                            @php $days = [1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday', 4 => 'Thursday', 5 => 'Friday', 6 => 'Saturday', 7 => 'Sunday']; @endphp
                            <x-td.table caption="Your weekly open hours ({{ $profile->scheduleTimezone() }})">
                                <thead><tr><th scope="col">Day</th><th scope="col">From</th><th scope="col">To</th></tr></thead>
                                <tbody>
                                    @foreach ($windows as $w)
                                        <tr><td>{{ $days[$w->weekday] ?? $w->weekday }}</td><td>{{ substr($w->start_time, 0, 5) }}</td><td>{{ substr($w->end_time, 0, 5) }}</td></tr>
                                    @endforeach
                                </tbody>
                            </x-td.table>
                            <div class="mt-4 flex flex-wrap gap-3">
                                <x-td.button href="{{ route('tutor.availability.index') }}" icon="calendar">Change hours and days off</x-td.button>
                                <x-td.button href="{{ route('teach.profile', ['step' => 'offering']) }}" variant="primary">Continue</x-td.button>
                            </div>
                        @endif
                        @break

                    @case('offering')
                        @if ($offerings->isEmpty())
                            <x-td.state kind="empty" title="No offering yet">An offering is what a learner books: a one-to-one lesson, a group class, a package. You see the commission and what you keep before you save it.
                                <x-slot:actions><x-td.button href="{{ route('teach.offerings.create', ['type' => 'one_to_one']) }}" variant="primary" icon="plus">Create your first offering</x-td.button></x-slot:actions>
                            </x-td.state>
                        @else
                            <ul class="grid gap-2">
                                @foreach ($offerings as $o)
                                    <li class="flex flex-wrap items-center justify-between gap-3 border-t border-td-line pt-2">
                                        <span><strong>{{ $o->title }}</strong> <span class="text-td-ink-2">{{ $o->typeEnum()->label() }}, {{ $o->duration_minutes }} min</span></span>
                                        <x-td.money :minor="$o->price_minor" :currency="$o->currency" />
                                    </li>
                                @endforeach
                            </ul>
                            <div class="mt-4 flex flex-wrap gap-3">
                                <x-td.button href="{{ route('teach.offerings') }}">Manage offerings</x-td.button>
                                <x-td.button href="{{ route('teach.profile', ['step' => 'send']) }}" variant="primary">Continue</x-td.button>
                            </div>
                        @endif
                        @break

                    @case('send')
                        @if (in_array($state['key'], ['draft', 'rejected', 'suspended'], true))
                            @if ($missing !== [])
                                <x-td.notice tone="warning" title="Not ready to send">Finish these steps first: {{ implode(', ', $missing) }}.</x-td.notice>
                            @endif
                            @error('submit')<x-td.notice tone="danger">{{ $message }}</x-td.notice>@enderror
                            <form method="POST" action="{{ route('teach.profile.submit') }}" class="mt-4 grid gap-4">
                                @csrf
                                @if ($state['key'] !== 'draft')
                                    <x-td.field label="What you changed since the decision" for="resubmission_note" hint="The reviewer reads this with your profile.">
                                        <x-td.textarea name="resubmission_note" rows="3" required :hint="true" />
                                    </x-td.field>
                                @endif
                                <p class="text-sm text-td-ink-2">An operator reads your profile, checks it follows the rules and approves it or says why not. Approval lets learners book you. It is not a verification level: that comes from reviewed evidence on the qualifications step.</p>
                                <div><x-td.button type="submit" variant="primary" icon="arrow-right" :disabled="$missing !== []">Send for review</x-td.button></div>
                            </form>
                        @elseif ($state['key'] === 'review')
                            <x-td.state kind="loading" title="Under review" icon="hourglass">Nothing to do now. You will see the decision here.</x-td.state>
                        @else
                            <x-td.state kind="success" title="Approved">Your storefront is live for signed-in learners.
                                <x-slot:actions><x-td.button href="{{ route('teach.home') }}" variant="primary">Go to your workspace</x-td.button></x-slot:actions>
                            </x-td.state>
                        @endif
                        @break
                @endswitch
            </x-td.panel>

            @if ($profile && in_array($step, ['qualifications', 'send'], true))
                <x-td.panel title="Verification levels">
                    <p class="mb-4 text-td-ink-2">From peer tutor to professional: each level needs a reviewed record of its kind. Dot.Tutor never assigns a level from what a profile says.</p>
                    <p class="mb-4">Your level now: @include('teach.partials.verify-level', ['level' => $verification['level'], 'reviewing' => $qualifications->contains('status', 'submitted')])</p>
                    <x-td.table caption="Verification levels and the evidence each needs" caption-hidden>
                        <thead><tr><th scope="col">Level</th><th scope="col">What Dot.Tutor checks</th><th scope="col">Evidence that counts</th></tr></thead>
                        <tbody>
                            @foreach ($levelsLadder as $lvl)
                                <tr @if ($verification['level'] === $lvl->value) aria-current="true" class="bg-td-primary-soft" @endif>
                                    <th scope="row">{{ $ladderShort[$lvl->value] }}@if ($verification['level'] === $lvl->value)<span class="td-sr"> (your level)</span>@endif</th>
                                    <td>{{ $lvl->description() }}</td>
                                    <td>{{ collect($lvl->supportingKinds())->map->label()->implode(', ') }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </x-td.table>
                </x-td.panel>
            @endif
        </div>
    </div>
</x-layouts::workspace>
