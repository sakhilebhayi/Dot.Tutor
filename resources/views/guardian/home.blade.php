{{-- Guardian home (brief section 15): what waits for a decision first, then each child. Phone-first. --}}
@php
    use App\Domain\Safety\Services\AccountStanding;
    $tz = config('tutor.timezone');
    $viewer = request()->user();
    $viewerIsMinor = app(AccountStanding::class)->isMinor($viewer);
@endphp
<x-layouts::learner title="Guardian">
    <x-td.page-head title="{{ $is_guardian ? 'Your children on Dot.Tutor' : 'Guardian' }}" :lede="$is_guardian ? 'Approve or decline what your child asks for, see their lessons, purchases and messages, and choose what needs your approval.' : 'A parent or guardian looks after a learner under 18 here: they approve lessons, purchases and rooms, and see messages and reports.'" />

    @if (! $is_guardian)
        <div class="grid gap-6">
            <x-td.panel>
                <x-td.state kind="empty" title="You are not linked to a learner" icon="hand-heart">
                    A learner invites their parent or guardian from their account. You get an email with a link; open it while signed in with that email address to give consent.
                </x-td.state>
            </x-td.panel>
            @if ($viewerIsMinor)
                <x-td.panel title="Invite your parent or guardian">
                    <p class="mt-0">Until a parent or guardian gives consent, you can read, report and ask support for help, but not book, pay, message tutors or join rooms.</p>
                    <form method="POST" action="{{ route('safety.guardians.invite') }}" class="grid gap-4">
                        @csrf
                        <x-td.field label="Their email address" for="g-email"><x-td.input name="email" id="g-email" type="email" required autocomplete="off" /></x-td.field>
                        <x-td.field label="They are your" for="g-rel"><x-td.select name="relationship" id="g-rel" :options="['parent' => 'Parent', 'legal_guardian' => 'Legal guardian', 'caregiver' => 'Caregiver']" /></x-td.field>
                        <div><x-td.button type="submit" variant="primary" size="lg">Send the invitation</x-td.button></div>
                    </form>
                </x-td.panel>
            @endif
        </div>
    @else
        <div class="grid gap-6">
            <x-td.panel title="Waiting for you" flush>
                @if ($waiting_total === 0)
                    <x-td.state kind="success" title="Nothing is waiting for your approval">When your child asks to book a lesson, buy something or join a paid room, it appears here and you get an email.</x-td.state>
                @else
                    @foreach ($children as $child)
                        @foreach ($child['pending'] as $approval)
                            @include('guardian._decision', ['approval' => $approval, 'childName' => $child['name']])
                        @endforeach
                    @endforeach
                @endif
            </x-td.panel>

            <x-td.panel title="{{ count($children) === 1 ? 'Your child' : 'Your children' }}" flush>
                <ul class="ops-work">
                    @foreach ($children as $child)
                        @php($next = $child['upcoming_lessons'][0] ?? null)
                        <li>
                            <div class="grid gap-1">
                                <p class="ops-work__title"><a href="{{ route('guardian.child', $child['id']) }}">{{ $child['name'] }}</a> <span class="ops-muted" style="font-weight:400">· {{ $child['age_band_label'] }}</span></p>
                                <p class="ops-work__meta">{{ $next ? 'Next lesson: '.($next['subject'] ?? 'a lesson').' with '.($next['tutor'] ?? 'a tutor').', '.\Illuminate\Support\Carbon::parse($next['starts_at'])->timezone($tz)->format('D j M, H:i') : 'No lessons booked' }} · {{ $child['messages']['conversations'] }} conversations</p>
                            </div>
                            @include('ops.partials.tag', match ($child['account']['status']) {
                                'suspended' => ['text' => 'Suspended', 'tone' => 'danger'],
                                'limited' => ['text' => 'Needs consent', 'tone' => 'warning'],
                                default => ['text' => 'Active', 'tone' => 'success'],
                            })
                        </li>
                    @endforeach
                </ul>
            </x-td.panel>
        </div>
    @endif
</x-layouts::learner>
