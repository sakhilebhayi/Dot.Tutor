@php
    use App\Domain\Safety\Enums\AgeBand;
    $kinds = ['booking' => 'Bookings', 'purchase' => 'Purchases', 'paid_room' => 'Paid rooms', 'live_lesson' => 'Live one-to-one lessons'];
@endphp
<x-layouts::operations title="Minors">
    @include('ops.safeguarding._head', ['title' => 'Minors', 'lede' => 'Every learner known to be under 18: their standing, guardians, and what waits for a guardian. The rules below are enforced on the server for every action, whatever screen it starts from.'])

    <div class="grid gap-6">
        <x-td.stat-strip label="Minors" :items="[
            ['label' => 'Minors on this page', 'value' => $rows->count(), 'hint' => $users->total().' learners flagged in all'],
            ['label' => 'Suspended minors', 'value' => $suspendedMinors],
            ['label' => 'Messages with contact details hidden', 'value' => $redacted30, 'hint' => 'last 30 days, all conversations with a minor'],
        ]" />

        <x-td.panel flush>
            @if ($rows->isEmpty())
                <x-td.state kind="empty" title="No learner is known to be a minor">A learner becomes known as a minor from a verified or declared date of birth, or when a guardian links.</x-td.state>
            @else
                <x-td.table caption="Learners under 18" caption-hidden>
                    <thead><tr><th scope="col">Learner</th><th scope="col">Age</th><th scope="col">Standing</th><th scope="col" class="is-num">Guardians</th><th scope="col" class="is-num">Waiting for a guardian</th></tr></thead>
                    <tbody>
                        @foreach ($rows as $row)
                            <tr>
                                <td><a href="{{ route('ops.users.show', $row['user']) }}">{{ $row['user']->name }}</a></td>
                                <td>{{ AgeBand::from($row['account']['age_band'])->label() }}</td>
                                <td>@include('ops.partials.tag', match ($row['account']['status']) {
                                    'suspended' => ['text' => 'Suspended', 'tone' => 'danger'],
                                    'limited' => ['text' => 'Limited, no guardian consent', 'tone' => 'warning'],
                                    default => ['text' => 'Active with consent', 'tone' => 'success'],
                                })</td>
                                <td class="is-num">{{ $row['guardians'] }}</td>
                                <td class="is-num">{{ $row['waiting'] }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </x-td.table>
                @if ($users->hasPages())<div class="px-5 py-3"><x-td.pagination :paginator="$users" label="Minor pages" /></div>@endif
            @endif
        </x-td.panel>

        <x-td.panel title="Rules in force for minors">
            <x-td.table caption="What needs a guardian's approval" dense>
                <thead><tr><th scope="col">Action</th><th scope="col">Under 13</th><th scope="col">13 to 17 (default; a guardian may change it)</th></tr></thead>
                <tbody>
                    @foreach ($kinds as $kind => $label)
                        <tr><td>{{ $label }}</td><td>{{ ($rules['approvals']['child'][$kind] ?? false) ? 'Guardian approves' : 'No approval' }}</td><td>{{ ($rules['approvals']['teen'][$kind] ?? false) ? 'Guardian approves' : 'No approval' }}</td></tr>
                    @endforeach
                </tbody>
            </x-td.table>
            <ul class="mt-5 mb-0 grid gap-2" style="max-width: var(--td-measure)">
                <li>Approval requests expire after {{ $rules['approvals']['expiry_hours'] }} hours.</li>
                <li>Messages with a minor need a context (a booking, a room or support); there are no direct conversations between an adult and a minor. A minor's guardians are in every conversation the minor is in.</li>
                <li>Email addresses, phone numbers, social handles and chat-app links are hidden in anything a minor sends or receives; the original is kept encrypted for this team only.</li>
                <li>Every adult in a lesson, a message or a room with a minor needs a current screening record; minors cannot book an unscreened tutor.</li>
                <li>Minors do not sell; children under 13 do not publish publicly and their room posts wait for a moderator.</li>
                <li>A learner whose age is not known is treated {{ $rules['unknown_age_policy'] === 'restrict' ? 'as a child' : 'as an adult (switch SAFETY_UNKNOWN_AGE_POLICY to restrict once every learner gives a date of birth)' }}.</li>
            </ul>
        </x-td.panel>
    </div>
</x-layouts::operations>
