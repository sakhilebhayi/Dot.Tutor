{{-- One approval a guardian decides. Posts to the safety domain, which checks the link and the expiry server-side. --}}
@php
    $kinds = ['booking' => 'A lesson booking', 'purchase' => 'A purchase', 'paid_room' => 'A paid study room', 'live_lesson' => 'A live one-to-one lesson'];
    $expires = \Illuminate\Support\Carbon::parse($approval['expires_at'])->timezone(config('tutor.timezone'));
@endphp
<form method="POST" action="{{ route('safety.guardian.approvals.decide', $approval['id']) }}" class="ops-decision" aria-label="{{ $kinds[$approval['kind']] ?? 'A request' }} for {{ $childName }}">
    @csrf
    <p class="ops-work__meta">{{ $kinds[$approval['kind']] ?? ucfirst(str_replace('_', ' ', $approval['kind'])) }} for {{ $childName }} · decide by {{ $expires->format('D j M, H:i') }}</p>
    <p class="ops-decision__what">{{ $approval['summary'] ?? 'No details were given.' }}</p>
    @if ($approval['amount_minor'] !== null)
        <p class="m-0">Cost: <strong>{{ \App\Domain\Money\Money::of((int) $approval['amount_minor'], $approval['currency'] ?: 'ZAR')->format() }}</strong>. Nothing is charged until you approve and someone pays.</p>
    @endif
    <x-td.field label="Your reason (optional; {{ $childName }} sees it if you decline)" for="note-{{ $approval['id'] }}" optional><x-td.input name="note" id="note-{{ $approval['id'] }}" maxlength="500" /></x-td.field>
    <div class="ops-decision__buttons">
        <x-td.button type="submit" name="decision" value="approve" variant="primary" size="lg" block>Approve</x-td.button>
        <x-td.button type="submit" name="decision" value="decline" size="lg" block>Decline</x-td.button>
    </div>
</form>
