@php
    use App\Domain\Community\Models\Room;
    use App\Domain\Community\Models\RoomMember;
    use App\Domain\Community\Support\Names;
@endphp
<x-layouts::learner :title="$room->title" :step="$step">
    <nav aria-label="Breadcrumb" class="mb-3 text-sm"><a class="td-link" href="{{ route('rooms.levels.show', $room->isForMinors() ? [$room->level, 'audience' => Room::UNDER_18] : [$room->level]) }}">{{ $room->level?->name }}, {{ $room->curriculum?->short_name ?: $room->curriculum?->name }}</a></nav>
    <x-td.page-head :title="$room->title" :lede="'A tutor room for '.$room->subject?->name.', run by '.Names::short($room->owner).'.'" />
    <div class="mb-6">@include('community.partials.scope', ['room' => $room])</div>

    <div class="td-panels td-panels--2">
        <x-td.panel title="About this room">
            <div class="whitespace-pre-line" style="max-width: var(--td-measure)">{{ $room->description ?: 'The tutor has not written a description.' }}</div>
        </x-td.panel>
        <x-td.panel title="Join">
            <div class="grid gap-4">
                <x-td.stat-strip :items="[
                    ['label' => 'Price', 'value' => $room->isPaid() ? \App\Domain\Money\Money::of($room->price_minor, $room->currency)->format() : 'Free'],
                    ['label' => 'Places left', 'value' => $room->capacity ? max(0, $room->capacity - $room->seats_taken) : 'No limit'],
                ]" />
                <p class="m-0 text-sm text-td-ink-2">{{ match ($room->join_rule) { 'approval' => 'The tutor accepts each request.', 'invite' => 'By invitation code from the tutor.', default => 'Anyone at this level can join.' } }}</p>

                @if ($member?->status === RoomMember::PENDING)
                    <x-td.notice tone="info">Your request is with the tutor.</x-td.notice>
                @elseif ($member?->status === RoomMember::AWAITING_GUARDIAN)
                    <x-td.notice tone="info" title="Waiting for a parent or guardian">
                        We asked them to approve this paid room. Once they have, continue below.
                    </x-td.notice>
                @elseif ($member?->status === RoomMember::AWAITING_PAYMENT)
                    <x-td.notice tone="info">Your place opens as soon as the payment is confirmed. If you closed the payment page, you can continue below.</x-td.notice>
                @elseif ($member?->status === RoomMember::REMOVED)
                    <x-td.notice tone="warning">The tutor removed you from this room.</x-td.notice>
                @endif

                @if ($member?->status !== RoomMember::REMOVED && $member?->status !== RoomMember::PENDING)
                    @if ($errors->has('community') || $errors->has('safety'))
                        <x-td.notice tone="danger">{{ $errors->first('community') ?: $errors->first('safety') }}</x-td.notice>
                    @endif
                    <form method="POST" action="{{ route('rooms.enrol', $room) }}" class="grid gap-3">
                        @csrf
                        <input type="hidden" name="idempotency_key" value="{{ 'room-'.$room->id.'-'.auth()->id().'-'.\Illuminate\Support\Str::uuid() }}">
                        @if ($room->join_rule === Room::INVITE)
                            <x-td.field label="Invitation code" for="code"><x-td.input name="code" id="code" maxlength="24" required autocomplete="off" /></x-td.field>
                        @endif
                        <div>
                            <x-td.button type="submit" variant="primary">
                                {{ $member?->status === RoomMember::AWAITING_GUARDIAN ? 'Check and continue' : ($room->isPaid() ? 'Pay and join' : ($room->join_rule === Room::APPROVAL ? 'Ask to join' : 'Join this room')) }}
                            </x-td.button>
                        </div>
                        @if ($room->isPaid())
                            <p class="m-0 text-sm text-td-ink-2">Payment is taken by Paystack. A learner under 18 needs a parent or guardian to approve first.</p>
                        @endif
                    </form>
                @endif
            </div>
        </x-td.panel>
    </div>
</x-layouts::learner>
