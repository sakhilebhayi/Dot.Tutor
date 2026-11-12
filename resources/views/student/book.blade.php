@php
    use App\Domain\Student\Support\Times;
    $isLesson = in_array($type->value, ['one_to_one', 'consultation', 'homework_help', 'exam_prep', 'revision'], true);
    $isWeekly = $type->value === 'recurring_weekly';
    $isPackage = $type->value === 'package';
    $isGroup = $type->value === 'group';
    $showTimes = $isLesson || $isWeekly || ($isPackage && $usablePackage);
    $free = (int) $data['price_minor'] === 0;
    $key = (string) \Illuminate\Support\Str::uuid();
@endphp
<x-student.shell :title="'Book: '.$data['title']">
    <x-td.page-head :title="$data['title']" :lede="$data['type_label'].' with '.$tutor['name'].' · '.$data['duration_minutes'].' minutes'">
        <x-slot:actions>
            <x-td.button variant="quiet" icon="arrow-left" :href="route('marketplace.tutors.show', $tutor['id'])">Back to the profile</x-td.button>
        </x-slot:actions>
    </x-td.page-head>

    @if ($blocked)
        <x-td.notice tone="warning" title="You cannot book this yet">
            @foreach ($blocked as $reason)<p style="margin:0">{{ is_array($reason) ? ($reason['message'] ?? '') : $reason }}</p>@endforeach
            @if ($ctx['limited'])<p style="margin:var(--td-space-2) 0 0"><a class="td-link" href="{{ route('safety.account') }}">Invite a parent or guardian</a></p>@endif
        </x-td.notice>
    @elseif ($ctx['minor'])
        <x-td.notice tone="info" title="Your parent or guardian is asked first">Your request waits for your parent or guardian to approve it. The tutor sees it after that, and nothing is paid until then.</x-td.notice>
    @endif
    @if ($errors->any())
        <x-td.notice tone="danger" title="That did not go through">
            @foreach ($errors->all() as $message)<p style="margin:0">{{ $message }}</p>@endforeach
        </x-td.notice>
    @endif

    <div class="st-cols">
        <div class="st-stack">
            @if ($isGroup)
                <x-td.panel title="Scheduled group lessons" flush>
                    @if ($groupSessions->isEmpty())
                        <x-td.state kind="empty" title="No group lesson is scheduled yet">The tutor schedules group lessons from this offering. Check back, or book a one-to-one lesson instead.</x-td.state>
                    @else
                        <ul class="st-list">
                            @foreach ($groupSessions as $group)
                                @php $left = max(0, (int) $group->capacity - (int) $group->seats_taken); @endphp
                                <li class="st-row">
                                    <div>
                                        <p class="st-row__title"><time datetime="{{ $group->starts_at->toIso8601String() }}">{{ Times::when($group->starts_at) }}</time></p>
                                        <p class="st-row__meta">{{ $group->duration_minutes }} min · {{ $left }} of {{ $group->capacity }} {{ \Illuminate\Support\Str::plural('seat', (int) $group->capacity) }} left</p>
                                    </div>
                                    <div class="st-row__end">
                                        @if (in_array($group->id, $mySeats, true))
                                            <x-td.button size="sm" :href="route('learn.lessons.show', $group)">Your seat</x-td.button>
                                        @elseif ($left === 0)
                                            <span class="td-badge td-badge--self"><x-td.icon name="x" />Full</span>
                                        @else
                                            <form method="POST" action="{{ route('learn.book.store', $offering->id) }}">
                                                @csrf
                                                <input type="hidden" name="session_id" value="{{ $group->id }}">
                                                <input type="hidden" name="idempotency_key" value="{{ $key }}-{{ $group->id }}">
                                                <x-td.button type="submit" size="sm" variant="primary" :disabled="(bool) $blocked">Take a seat</x-td.button>
                                            </form>
                                        @endif
                                    </div>
                                </li>
                            @endforeach
                        </ul>
                        <p class="st-small" style="padding:0 var(--td-row-pad-x) var(--td-space-3)">{{ Times::zoneNote() }}</p>
                    @endif
                </x-td.panel>
            @elseif ($isPackage && ! $usablePackage)
                <x-td.panel title="Buy the package first">
                    <div class="st-stack">
                        <p style="margin:0">{{ $data['package_lessons'] }} lessons of {{ $data['duration_minutes'] }} minutes, to use within {{ $data['package_valid_days'] }} days. Book each lesson at a time that suits you once the package is paid.</p>
                        @php $waiting = $packages->first(fn ($p) => in_array($p->status, ['awaiting_payment', 'awaiting_guardian'], true)); @endphp
                        @if ($waiting && $waiting->status === 'awaiting_guardian')
                            <x-td.notice tone="info">Your parent or guardian has been asked to approve this package.</x-td.notice>
                        @endif
                        <form method="POST" action="{{ route('learn.book.store', $offering->id) }}" class="st-form__actions">
                            @csrf
                            <input type="hidden" name="idempotency_key" value="{{ $key }}">
                            <x-td.button type="submit" variant="primary" :disabled="(bool) $blocked">{{ $waiting && $waiting->status === 'awaiting_payment' ? 'Pay for the package' : 'Buy the package' }}</x-td.button>
                        </form>
                    </div>
                </x-td.panel>
            @endif

            @if ($showTimes)
                <form method="POST" action="{{ route('learn.book.store', $offering->id) }}" class="st-stack">
                    @csrf
                    <input type="hidden" name="idempotency_key" value="{{ $key }}">
                    @if ($usablePackage)<input type="hidden" name="package_purchase_id" value="{{ $usablePackage->id }}">@endif

                    <x-td.panel :title="$isWeekly ? 'Choose the first lesson' : 'Choose a time'">
                        @if ($days === [])
                            <x-td.state kind="empty" title="No open times in the next two weeks">The tutor has not opened any time in that period. Try another tutor, or check back later.</x-td.state>
                        @else
                            <fieldset aria-describedby="times-hint">
                                <legend class="td-sr">Open times</legend>
                                @foreach ($days as $day)
                                    <p class="st-day">{{ Times::day($day['slots'][0]) }}</p>
                                    <div class="st-times">
                                        @foreach ($day['slots'] as $slot)
                                            @php $iso = $slot->utc()->toIso8601String(); @endphp
                                            <label class="st-time">
                                                <input type="radio" name="starts_at" value="{{ $iso }}" required @checked(old('starts_at', $preselect) === $iso)>
                                                <span>{{ Times::clock($slot) }}</span>
                                            </label>
                                        @endforeach
                                    </div>
                                @endforeach
                                <p class="td-hint" id="times-hint" style="margin:var(--td-space-3) 0 0">Only times the tutor has opened and nobody has booked. {{ Times::zoneNote() }}</p>
                            </fieldset>
                        @endif
                    </x-td.panel>

                    <x-td.panel title="Details">
                        <div class="st-form">
                            @if (count($data['subjects']) === 1)
                                <input type="hidden" name="subject_id" value="{{ $data['subjects'][0]['id'] }}">
                                <p style="margin:0"><span class="st-small">Subject</span><br>{{ $data['subjects'][0]['name'] }}</p>
                            @else
                                <x-td.field label="Subject" for="subject_id">
                                    <x-td.select name="subject_id" required placeholder="Choose a subject" :options="collect($data['subjects'])->mapWithKeys(fn ($s) => [$s['id'] => $s['name']])->all()" />
                                </x-td.field>
                            @endif
                            @if ($data['format'] === 'either')
                                <fieldset>
                                    <legend>How</legend>
                                    <div class="st-inline st-inline--loose">
                                        <x-td.check type="radio" name="delivery" value="online" :checked="true">Online</x-td.check>
                                        <x-td.check type="radio" name="delivery" value="in_person">In person @if ($data['area'])({{ $data['area'] }})@endif</x-td.check>
                                    </div>
                                </fieldset>
                            @else
                                <p style="margin:0"><span class="st-small">How</span><br>{{ $data['format'] === 'online' ? 'Online' : 'In person'.($data['area'] ? ' ('.$data['area'].')' : '') }}</p>
                            @endif
                            @if ($isWeekly)
                                <x-td.field label="How many weeks" for="weeks" hint="Same day and time each week. Weeks that clash are skipped and listed.">
                                    <x-td.select name="weeks" :hint="true" :value="$data['recurring_weeks']" :options="collect(range(2, max(2, (int) $data['recurring_weeks'])))->mapWithKeys(fn ($w) => [$w => $w.' weeks'])->all()" />
                                </x-td.field>
                            @endif
                            <x-td.field label="Anything the tutor should know" for="notes" optional hint="What you are working on, a topic or a test date. Do not share phone numbers or addresses here.">
                                <x-td.textarea name="notes" rows="3" maxlength="2000" :hint="true" />
                            </x-td.field>
                        </div>
                    </x-td.panel>

                    <div class="st-form__actions">
                        <x-td.button type="submit" variant="primary" size="lg" :disabled="(bool) $blocked || $days === []">{{ $isWeekly ? 'Request weekly lessons' : 'Request this lesson' }}</x-td.button>
                    </div>
                </form>
            @endif
        </div>

        <aside class="st-stack" aria-label="Price and terms">
            <x-td.panel title="What you pay">
                <div class="st-stack">
                    @if ($usablePackage)
                        <p style="margin:0">Uses one lesson from your package. {{ $usablePackage->creditsLeft() }} {{ \Illuminate\Support\Str::plural('lesson', $usablePackage->creditsLeft()) }} left.</p>
                    @else
                        <div class="st-total">
                            <span>{{ $isPackage ? 'Package of '.$data['package_lessons'] : ($isWeekly ? 'Each lesson' : ($isGroup ? 'Your seat' : 'This lesson')) }}</span>
                            @if ($free)<span>Free</span>@else<x-td.money :minor="$data['price_minor']" :currency="$data['currency']" />@endif
                        </div>
                        @unless ($free)
                            <p class="st-small" style="margin:0">This is the whole price. Dot.Tutor's commission comes out of the tutor's share; nothing is added to what you pay.</p>
                            <p class="st-small" style="margin:0">You pay only after the tutor accepts{{ $ctx['minor'] ? ' and your parent or guardian approves' : '' }}. Payment is by Paystack.</p>
                        @endunless
                    @endif
                </div>
            </x-td.panel>
            <x-td.panel title="If plans change">
                <p style="margin:0">{{ $data['cancellation']['summary'] }}</p>
                <p class="st-small" style="margin:var(--td-space-2) 0 0">If the tutor cancels, you get everything back.</p>
            </x-td.panel>
            <x-td.panel title="Your tutor">
                <div class="st-inline">
                    <x-td.avatar :name="$tutor['name']" :src="$tutor['has_photo'] ? route('marketplace.tutors.photo', $tutor['id']) : null" size="sm" decorative />
                    <a class="td-link" href="{{ route('marketplace.tutors.show', $tutor['id']) }}">{{ $tutor['name'] }}</a>
                </div>
                <div class="st-inline" style="margin-top:var(--td-space-2)">@include('student.partials.verification', ['verification' => $tutor['verification']])</div>
            </x-td.panel>
        </aside>
    </div>
</x-student.shell>
