{{-- Audit entries (safety's append-only, hash-chained log), in plain words (D-2209): the person's name, the action as
     a sentence (a link that shows only that kind), what it was about, and the details as label: value. --}}
@php
    $plain = \App\Domain\Ops\Support\PlainWords::class;
    $actorNames = $entries->isEmpty() ? [] : \App\Models\User::query()->whereIn('id', $entries->pluck('actor_id')->filter()->unique())->pluck('name', 'id')->all();
    $tz = \App\Domain\Student\Support\Times::zone();
    $subjects = $entries->isEmpty() ? [] : $plain::subjects($entries, $tz);
@endphp
<x-td.panel title="Audit history" flush>
    @if ($entries->isEmpty())
        <x-td.state kind="empty" title="No audit entries">Nothing has been recorded{{ isset($filtered) && $filtered ? ' for these filters' : '' }}.</x-td.state>
    @else
        <x-td.table :caption="$caption" caption-hidden dense stack>
            <thead><tr><th scope="col">When</th><th scope="col">Who</th><th scope="col" data-stack="title">Action</th><th scope="col">About</th><th scope="col" data-stack="wide">Details</th></tr></thead>
            <tbody>
                @foreach ($entries as $e)
                    <tr>
                        <td class="ops-num text-sm" style="white-space:nowrap">{{ $e->created_at?->timezone($tz)->format('j M Y H:i:s') }}</td>
                        <td class="text-sm">{{ $plain::actor($e->actor_id, $e->actor_label, $actorNames) }}</td>
                        <td class="text-sm">
                            <a href="{{ route('ops.audit', ['action' => $e->action]) }}">{{ $plain::action($e->action) }}</a>
                            @if ($area = $plain::area($e->action))<span class="block ops-muted">{{ $area }}</span>@endif
                        </td>
                        <td class="text-sm">{{ $plain::subject($e->subject_type, $e->subject_id, $subjects) }}</td>
                        <td class="text-sm">{{ $plain::details($e->context ?? []) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </x-td.table>
    @endif
</x-td.panel>
