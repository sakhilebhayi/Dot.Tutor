<form method="POST" action="{{ route('ops.reviews.resolve', $booking) }}" class="ops-act">
    @csrf
    <x-td.field label="Decision and why" for="res-reason-{{ $booking->id }}"><x-td.input name="reason" id="res-reason-{{ $booking->id }}" required minlength="5" /></x-td.field>
    <x-td.field label="Refund to the learner" for="res-pct-{{ $booking->id }}" class="w-40"><x-td.select name="refund_percent" id="res-pct-{{ $booking->id }}" :options="['0' => 'None', '50' => '50%', '100' => '100%']" value="0" /></x-td.field>
    <x-td.button type="submit" name="outcome" value="completed" size="sm">Lesson stands</x-td.button>
    <x-td.button type="submit" name="outcome" value="cancelled" size="sm" variant="danger">Void the lesson</x-td.button>
</form>
