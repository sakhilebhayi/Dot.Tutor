<x-app-layout>
<x-safety::frame>
    <h1>Your guardian page</h1>
    <p class="lede">Lessons, approvals, purchases, messages and reports for each learner you look after.</p>

    @forelse ($children as $child)
        <section class="panel" aria-labelledby="child-{{ $child['id'] }}">
            <h2 id="child-{{ $child['id'] }}" style="margin-top:0">{{ $child['name'] }} <span class="muted">{{ $child['age_band_label'] }}</span></h2>
            <div class="row"><span>Account</span><span class="tag tag-{{ $child['account']['status'] }}">{{ ucfirst($child['account']['status']) }}</span></div>

            <h3>Waiting for you</h3>
            @php $pending = array_values(array_filter($child['approvals'], fn ($a) => $a['status'] === 'pending')); @endphp
            @forelse ($pending as $approval)
                <form method="POST" action="{{ route('safety.guardian.approvals.decide', $approval['id']) }}" class="row">
                    @csrf
                    <span>{{ $approval['summary'] ?? str_replace('_', ' ', $approval['kind']) }}
                        @if ($approval['amount_minor'] !== null)<span class="muted">{{ $approval['currency'] }} {{ number_format($approval['amount_minor'] / 100, 2) }}</span>@endif
                    </span>
                    <span>
                        <button type="submit" name="decision" value="approve" style="margin-top:0">Approve</button>
                        <button type="submit" name="decision" value="decline" class="secondary" style="margin-top:0">Decline</button>
                    </span>
                </form>
            @empty
                <p class="muted">Nothing is waiting for your approval.</p>
            @endforelse

            <h3>Upcoming lessons</h3>
            @forelse ($child['upcoming_lessons'] as $lesson)
                <div class="row"><span>{{ $lesson['subject'] }} with {{ $lesson['tutor'] }}</span>
                    <span class="muted">{{ \Illuminate\Support\Carbon::parse($lesson['starts_at'])->timezone(config('tutor.timezone'))->format('D j M, H:i') }} · <span class="tag tag-{{ $lesson['status'] }}">{{ ucfirst($lesson['status']) }}</span></span></div>
            @empty
                <p class="muted">No lessons booked.</p>
            @endforelse

            <h3>Purchases</h3>
            @forelse ($child['purchases'] as $purchase)
                <div class="row"><span>{{ $purchase['summary'] ?? 'Purchase' }}</span><span class="tag tag-{{ $purchase['status'] }}">{{ ucfirst($purchase['status']) }}</span></div>
            @empty
                <p class="muted">No purchase requests.</p>
            @endforelse
            <p class="muted">Order history appears here once payments are connected.</p>

            <h3>Messages</h3>
            <p>{{ $child['messages']['conversations'] }} conversation(s), {{ $child['messages']['unread_for_you'] }} unread by you.
                @if ($child['messages']['redacted_messages'] > 0) {{ $child['messages']['redacted_messages'] }} message(s) had contact details hidden.@endif
                <a href="{{ route('safety.conversations.index') }}">Read them</a></p>

            <h3>Reports</h3>
            @forelse ($child['reports'] as $report)
                <div class="row"><span>{{ $report['category_label'] }} {{ $report['filed_by_child'] ? '(reported by '.$child['name'].')' : '' }}</span><span class="tag tag-{{ $report['status'] }}">{{ ucfirst($report['status']) }}</span></div>
            @empty
                <p class="muted">No reports.</p>
            @endforelse

            <h3>Settings</h3>
            <form method="POST" action="{{ route('safety.guardian.links.recording', \App\Domain\Safety\Models\GuardianLink::query()->active()->where('learner_id', $child['id'])->where('guardian_id', $guardian['id'])->value('id')) }}">
                @csrf
                <input type="hidden" name="consent" value="{{ $child['recording_consent'] ? 0 : 1 }}">
                <p>Lesson recording: <strong>{{ $child['recording_consent'] ? 'allowed' : 'not allowed' }}</strong></p>
                <button type="submit" class="secondary" style="margin-top:0">{{ $child['recording_consent'] ? 'Stop recordings' : 'Allow recordings' }}</button>
            </form>
        </section>
    @empty
        <p>No learner is linked to you yet. A learner sends you an invitation from their own account.</p>
    @endforelse
</x-safety::frame>
</x-app-layout>
