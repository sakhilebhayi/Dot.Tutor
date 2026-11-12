@php
    use App\Domain\Student\Support\Times;
    $status = $session->status;
    $group = $session->isGroup();
    $mine = $seat ?? null;
    $payState = $group ? ($mine['state'] ?? null) : $status;
    $priceMinor = $group ? ($mine['price']['amount_minor'] ?? 0) : $data['price']['amount_minor'];
    $currency = $group ? ($mine['price']['currency'] ?? 'ZAR') : $data['price']['currency'];
    $labels = [
        'requested' => 'You asked for this lesson', 'held_for_guardian' => 'Waiting for your parent or guardian',
        'guardian_approved' => 'Your parent or guardian approved', 'guardian_declined' => 'Your parent or guardian said no',
        'accepted' => 'The tutor accepted', 'declined' => 'The tutor declined', 'paid' => 'Paid', 'confirmed' => 'Confirmed',
        'started' => 'The lesson started', 'completed' => 'The lesson was completed', 'cancelled' => 'Cancelled',
        'expired' => 'Lapsed: it was not accepted or paid in time', 'no_show' => 'Recorded as missed', 'disputed' => 'A problem was reported',
        'dispute_resolved' => 'The problem was resolved', 'refunded' => 'Refunded', 'payment_after_release' => 'Paid after the hold ran out',
        'scheduled' => 'Scheduled by the tutor', 'seat_requested' => 'You asked for a seat', 'seat_paid' => 'Seat paid',
        'seat_cancelled' => 'Seat cancelled', 'seat_expired' => 'Seat hold lapsed', 'seat_refunded' => 'Seat refunded',
    ];
    $events = collect($data['events'] ?? [])->filter(fn ($e) => ! $group || $e['participant_id'] === null || $e['participant_id'] === ($mine['id'] ?? -1));
    $upcoming = match ($payState) {
        'awaiting_guardian' => array_values(array_filter(['Your parent or guardian approves', $group ? null : 'The tutor accepts', $priceMinor > 0 ? 'You pay' : null, 'The lesson'])),
        'pending', 'requested' => array_values(array_filter([$priceMinor > 0 ? 'You pay' : null, 'The lesson'])),
        'awaiting_payment' => ['The lesson'],
        'confirmed' => [],
        default => [],
    };
    $current = match ($payState) {
        'awaiting_guardian' => 'Waiting for your parent or guardian',
        'pending', 'requested' => 'Waiting for the tutor to accept',
        'awaiting_payment' => 'Pay to confirm',
        'confirmed' => 'The lesson, '.Times::relative($session->starts_at),
        'in_progress' => 'Happening now',
        default => null,
    };
@endphp
<x-student.shell :title="($data['subject']['name'] ?? 'Lesson').' with '.$data['tutor']['name']">
    <x-td.page-head :title="($data['subject']['name'] ?? 'Lesson').' with '.$data['tutor']['name']"
        :lede="Times::when($session->starts_at).' · '.$data['duration_minutes'].' minutes · '.($data['delivery'] === 'in_person' ? 'In person' : 'Online')">
        <x-slot:actions>
            <x-td.button variant="quiet" icon="arrow-left" :href="route('marketplace.bookings')">All lessons</x-td.button>
        </x-slot:actions>
    </x-td.page-head>

    @if (session('error'))<x-td.notice tone="danger">{{ session('error') }}</x-td.notice>@endif
    @if ($errors->any())<x-td.notice tone="danger" title="That did not go through">{{ $errors->first() }}</x-td.notice>@endif

    <div class="st-cols">
        <div class="st-stack">
            <section class="st-next" aria-labelledby="state-title">
                <p class="st-next__when">@include('student.partials.lesson-state', ['status' => $group && $mine ? $mine['state'] : $status])</p>
                <h2 class="st-next__what" id="state-title">{{ $group && $mine ? ($mine['next_step'] ?? $data['next_step']) : $data['next_step'] }}</h2>
                @if ($data['decision_reason'])
                    <p class="st-muted" style="margin:0">Reason given: {{ $data['decision_reason'] }}</p>
                @endif

                @if ($isLearner && $payState === 'awaiting_payment')
                    <div class="st-stack st-stack--tight" style="margin-top:var(--td-space-2)">
                        @if ($paymentNotice)
                            <x-td.notice tone="warning">{{ $paymentNotice }} Nothing has been charged.</x-td.notice>
                        @else
                            @if ($testMode)<p class="st-small" style="margin:0">Test mode: Paystack's test cards work and no real money moves.</p>@endif
                            <form method="POST" action="{{ route('learn.lessons.pay', $session) }}" class="st-inline">
                                @csrf
                                <x-td.button type="submit" variant="primary" size="lg" icon="wallet">Pay <x-td.money :minor="$priceMinor" :currency="$currency" /> with Paystack</x-td.button>
                            </form>
                            <p class="st-small" style="margin:0">You finish on Paystack's secure page and come back here. The lesson is confirmed when Paystack confirms the payment.</p>
                        @endif
                        @if ($data['payment_hold_expires_at'])<p class="st-small" style="margin:0">Held for you until {{ Times::when($data['payment_hold_expires_at']) }}.</p>@endif
                    </div>
                @endif

                @if ($classroomUrl)
                    <div class="st-inline" style="margin-top:var(--td-space-2)"><x-td.button variant="primary" icon="video" :href="$classroomUrl">Join the classroom</x-td.button></div>
                @elseif (in_array($status, ['confirmed', 'in_progress'], true) && $data['delivery'] === 'online')
                    <p class="st-small" style="margin:0">Your tutor shares how to join the online lesson before it starts.</p>
                @endif
            </section>

            <x-td.panel title="What has happened">
                <x-td.timeline label="Lesson history">
                    @foreach ($events as $event)
                        <x-td.timeline-item :title="$labels[$event['event']] ?? ucfirst(str_replace('_', ' ', $event['event']))" state="done"
                            :meta="$event['at'] ? Times::when($event['at']) : null">@if ($event['reason'] && in_array($event['event'], ['declined', 'cancelled', 'disputed', 'dispute_resolved'], true)){{ $event['reason'] }}@endif</x-td.timeline-item>
                    @endforeach
                    @if ($events->isEmpty())
                        <x-td.timeline-item title="Booked" state="done" :meta="Times::when($session->created_at)" />
                    @endif
                    @if ($current)
                        <x-td.timeline-item :title="$current" state="current" />
                    @endif
                    @foreach ($upcoming as $step)
                        <x-td.timeline-item :title="$step" state="upcoming" />
                    @endforeach
                </x-td.timeline>
                <p class="st-small" style="margin:var(--td-space-3) 0 0">{{ Times::zoneNote() }}</p>
            </x-td.panel>

            @if ($canReview || $myReview)
                <x-td.panel title="How did it go?" id="review">
                    @if ($myReview)
                        <p style="margin:0">You gave {{ $myReview->rating }} out of 5.@if ($myReview->review) "{{ $myReview->review }}"@endif</p>
                        <p class="st-small" style="margin:var(--td-space-2) 0 0">{{ $myReview->status === 'published' ? 'Published on the tutor\'s profile.' : 'Held for a quick check before it shows.' }}</p>
                    @else
                        <form method="POST" action="{{ route('learn.lessons.review', $session) }}" class="st-form">
                            @csrf
                            <fieldset>
                                <legend>Stars</legend>
                                <div class="st-stars">
                                    @foreach ([5, 4, 3, 2, 1] as $stars)
                                        <label><input type="radio" name="rating" value="{{ $stars }}" required @checked((int) old('rating') === $stars)> {{ $stars }} <x-td.icon name="star" /></label>
                                    @endforeach
                                </div>
                                @error('rating')<p class="td-error"><x-td.icon name="circle-alert" />{{ $message }}</p>@enderror
                            </fieldset>
                            <x-td.field label="What helped, or what could be better?" for="review-text" optional hint="Reviews with phone numbers or other contact details are held for a check.">
                                <x-td.textarea name="review" id="review-text" rows="3" maxlength="2000" :hint="true" />
                            </x-td.field>
                            <div class="st-form__actions"><x-td.button type="submit" variant="primary">Send the review</x-td.button></div>
                        </form>
                    @endif
                </x-td.panel>
            @endif
        </div>

        <aside class="st-stack" aria-label="Lesson details">
            <x-td.panel title="Details">
                <dl class="st-facts">
                    <dt>Tutor</dt><dd><a class="td-link" href="{{ route('marketplace.tutors.show', $data['tutor']['id']) }}">{{ $data['tutor']['name'] }}</a></dd>
                    @if ($data['offering'])<dt>Booked as</dt><dd>{{ $data['offering']['title'] }}</dd>@endif
                    <dt>When</dt><dd><time datetime="{{ $data['starts_at'] }}">{{ Times::when($session->starts_at) }}</time>, {{ $data['duration_minutes'] }} min</dd>
                    <dt>Price</dt><dd>@if ($priceMinor > 0)<x-td.money :minor="$priceMinor" :currency="$currency" />@else Free or covered @endif</dd>
                    @if ($data['location_note'])<dt>Where</dt><dd>{{ $data['location_note'] }}</dd>@endif
                    @if ($group)<dt>Group</dt><dd>{{ $data['seats_taken'] ?? 0 }} of {{ $data['capacity'] ?? 0 }} seats taken</dd>@endif
                </dl>
                @if ($calendarUrl)
                    <div class="st-inline" style="margin-top:var(--td-space-3)"><x-td.button size="sm" icon="calendar" :href="$calendarUrl">Add to my calendar</x-td.button></div>
                @endif
            </x-td.panel>

            @if ($isLearner && in_array($payState, ['awaiting_guardian', 'pending', 'requested', 'awaiting_payment', 'confirmed'], true))
                <x-td.panel title="Cancel">
                    @if ($data['cancellation'])<p style="margin:0">{{ $data['cancellation'] }}</p>@endif
                    @if ($cancelPreview)
                        <p style="margin:var(--td-space-2) 0 0"><strong>If you cancel now:</strong>
                            @if (! $cancelPreview['paid']) nothing has been paid, so nothing is due.
                            @elseif ($cancelPreview['percent'] === 100) you are due a full refund of <x-td.money :minor="$cancelPreview['minor']" :currency="$cancelPreview['currency']" />.
                            @elseif ($cancelPreview['percent'] === 0) it is too close to the start for a refund.
                            @else you are due {{ $cancelPreview['percent'] }}% back, <x-td.money :minor="$cancelPreview['minor']" :currency="$cancelPreview['currency']" />.
                            @endif
                        </p>
                        @if ($cancelPreview['paid'] && $cancelPreview['percent'] > 0)<p class="st-small" style="margin:var(--td-space-1) 0 0">Refunds go back to the card through Paystack once Dot.Tutor's finance team processes them.</p>@endif
                    @endif
                    <details style="margin-top:var(--td-space-3)">
                        <summary class="td-btn td-btn--sm td-btn--danger">{{ $group ? 'Cancel my seat' : 'Cancel this lesson' }}</summary>
                        <form method="POST" action="{{ route('learn.lessons.cancel', $session) }}" class="st-form" style="margin-top:var(--td-space-3)">
                            @csrf
                            <x-td.field label="Reason" for="cancel-reason" optional hint="The tutor sees this.">
                                <x-td.textarea name="reason" id="cancel-reason" rows="2" maxlength="1000" :hint="true" />
                            </x-td.field>
                            <x-td.button type="submit" variant="danger" size="sm">Yes, cancel</x-td.button>
                        </form>
                    </details>
                </x-td.panel>
            @endif

            @include('student.partials.report', ['type' => 'tutor_profile', 'id' => $data['tutor']['id'], 'label' => 'a problem with this tutor'])
        </aside>
    </div>
</x-student.shell>
