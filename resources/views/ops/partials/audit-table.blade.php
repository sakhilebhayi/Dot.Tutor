{{-- Audit entries (safety's append-only, hash-chained log). Context is metadata only; shown as key: value. --}}
<x-td.panel title="Audit history" flush>
    @if ($entries->isEmpty())
        <x-td.state kind="empty" title="No audit entries">Nothing has been recorded{{ isset($filtered) && $filtered ? ' for these filters' : '' }}.</x-td.state>
    @else
        <x-td.table :caption="$caption" caption-hidden dense>
            <thead><tr><th scope="col">When</th><th scope="col">Who</th><th scope="col">Action</th><th scope="col">About</th><th scope="col">Details</th></tr></thead>
            <tbody>
                @foreach ($entries as $e)
                    <tr>
                        <td class="ops-num text-sm" style="white-space:nowrap">{{ $e->created_at?->timezone(config('tutor.timezone'))->format('j M Y H:i:s') }}</td>
                        <td class="text-sm">{{ $e->actor_label ?: ($e->actor_id ? 'User '.$e->actor_id : 'System') }}</td>
                        <td class="text-sm"><code>{{ $e->action }}</code></td>
                        <td class="text-sm">{{ $e->subject_type ? class_basename(str_replace('.', '\\', $e->subject_type)).' '.$e->subject_id : '—' }}</td>
                        <td class="text-sm">{{ collect($e->context ?? [])->map(fn ($v, $k) => $k.': '.(is_scalar($v) || $v === null ? var_export($v, true) : json_encode($v)))->take(6)->join('; ') }}</td>
                    </tr>
                @endforeach
            </tbody>
        </x-td.table>
    @endif
</x-td.panel>
