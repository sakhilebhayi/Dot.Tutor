@php
    use App\Domain\Community\Models\Room;
    $offeringOptions = [];
    foreach ($curricula as $curriculum) {
        foreach ($curriculum->levels as $level) {
            foreach ($level->offerings->sortBy(fn ($o) => $o->subject?->name) as $offering) {
                $offeringOptions[] = ['value' => $offering->id, 'label' => $level->name.' · '.$offering->subject?->name, 'group' => $curriculum->name];
            }
        }
    }
@endphp
<x-layouts::workspace title="Tutor rooms">
    <x-td.page-head title="Tutor rooms" lede="Run your own room or workshop series for one subject and level: free or paid, open, by request or by invitation." />

    <div class="td-panels td-panels--2">
        <x-td.panel title="Your rooms" flush>
            @if ($rooms->isEmpty())
                <x-td.state kind="empty" title="You do not run a room yet">Start one with the form. Learners find it in the subject's room and on the level page.</x-td.state>
            @else
                @if ($pending > 0)
                    <p class="m-0 border-b border-td-line px-5 py-3"><strong>{{ trans_choice('{1} 1 learner is|[2,*] :count learners are', $pending) }} waiting for you to accept them.</strong></p>
                @endif
                <x-td.table caption="Your tutor rooms" caption-hidden>
                    <thead><tr><th>Room</th><th>Level</th><th>Price</th><th class="is-num">Places</th><th>State</th></tr></thead>
                    <tbody>
                        @foreach ($rooms as $room)
                            <tr>
                                <td><a class="td-link" href="{{ route('rooms.show', [$room, 'tab' => 'groups']) }}">{{ $room->title }}</a><span class="block text-sm text-td-ink-2">{{ $room->subject?->name }} · {{ $room->audienceLabel() }}</span></td>
                                <td><x-td.pill kind="level" :stage="$room->level?->stage?->code">{{ $room->level?->name }}</x-td.pill></td>
                                <td>@if ($room->isPaid())<x-td.money :minor="$room->price_minor" :currency="$room->currency" />@else Free @endif</td>
                                <td class="is-num">{{ $room->seats_taken }}{{ $room->capacity ? ' of '.$room->capacity : '' }}</td>
                                <td>{{ $room->status === 'active' ? 'Open' : 'Archived' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </x-td.table>
            @endif
        </x-td.panel>

        <x-td.panel title="Start a room">
            @unless ($approved)
                <x-td.notice tone="info" title="For approved tutors">Rooms are run by tutors whose profile Dot.Tutor has approved. Finish your tutor profile first.</x-td.notice>
            @else
                @if ($errors->has('community') || $errors->has('safety'))
                    <x-td.notice tone="danger" class="mb-4">{{ $errors->first('community') ?: $errors->first('safety') }}</x-td.notice>
                @endif
                <form method="POST" action="{{ route('rooms.tutor-rooms.store') }}" class="grid gap-4">
                    @csrf
                    <x-td.field label="Subject and level" for="curriculum_subject_level_id" :error="$errors->first('curriculum_subject_level_id')">
                        <x-td.select name="curriculum_subject_level_id" id="curriculum_subject_level_id" :options="$offeringOptions" placeholder="Choose a subject and level" />
                    </x-td.field>
                    <x-td.field label="Title" for="title" :error="$errors->first('title')"><x-td.input name="title" id="title" maxlength="160" required placeholder="Matric maths: Paper 1 workshops" /></x-td.field>
                    <x-td.field label="What learners get" for="description" optional><x-td.textarea name="description" id="description" rows="4" maxlength="4000" /></x-td.field>
                    <x-td.field label="Who it is for" for="audience" hint="A room for learners under 18 needs your safeguarding screening on record.">
                        <x-td.select name="audience" id="audience" :options="[Room::UNDER_18 => 'Learners under 18', Room::ADULT => 'Adults (18 or older)']" :hint="true" />
                    </x-td.field>
                    <x-td.field label="Teaching language" for="language" optional>
                        <x-td.select name="language" id="language" placeholder="Not stated" :options="$languages->mapWithKeys(fn ($l) => [$l->code => $l->name_native && $l->name_native !== $l->name_en ? $l->name_native.' ('.$l->name_en.')' : $l->name_en])->all()" />
                    </x-td.field>
                    <div class="grid gap-4 sm:grid-cols-2">
                        <x-td.field label="Who can join" for="join_rule"><x-td.select name="join_rule" id="join_rule" :options="['open' => 'Anyone at this level', 'approval' => 'I accept each request (free rooms)', 'invite' => 'With my invitation code']" /></x-td.field>
                        <x-td.field label="Places" for="capacity" optional><x-td.input type="number" name="capacity" id="capacity" min="{{ config('community.room_capacity.min') }}" max="{{ config('community.room_capacity.max') }}" /></x-td.field>
                    </div>
                    <x-td.field label="Price in rand" for="price_rand" optional hint="Leave empty for a free room. Learners pay once through Dot.Tutor; the platform's commission applies.">
                        <x-td.input type="number" name="price_rand" id="price_rand" min="0" step="0.01" :hint="true" />
                    </x-td.field>
                    <div><x-td.button type="submit" variant="primary" icon="plus">Open the room</x-td.button></div>
                </form>
            @endunless
        </x-td.panel>
    </div>
</x-layouts::workspace>
