<x-layouts::operations title="Audit log">
    <x-td.page-head title="Audit log" lede="Every moderation, safeguarding, verification, money and admin action, append-only. Each row's hash covers the one before it, so an edit made around the application shows here." />

    <div class="grid gap-6">
        @if (! $checked)
            <x-td.notice tone="info" title="Chain check">The chain is checked automatically every night. <a href="{{ route('ops.audit', array_merge($filters, ['verify' => 1])) }}">Check all {{ number_format($total) }} entries now</a>.</x-td.notice>
        @elseif ($brokenAt !== null)
            <x-td.notice tone="danger" title="The chain is broken at entry {{ $brokenAt }}">A row was changed or removed outside the application. Compare with the latest backup and tell the owner.</x-td.notice>
        @else
            <x-td.notice tone="success" title="Chain intact">All {{ number_format($total) }} entries check out against the hashes before them.</x-td.notice>
        @endif

        <form method="GET" action="{{ route('ops.audit') }}" class="ops-act" role="search">
            <x-td.field label="Kind of action" for="f-action" hint="Select an action in the list to see only that kind; the box holds its code."><x-td.input name="action" id="f-action" :value="$filters['action'] ?? ''" hint /></x-td.field>
            <x-td.field label="Done by (account number)" for="f-actor"><x-td.input name="actor_id" id="f-actor" type="number" min="1" :value="$filters['actor_id'] ?? ''" /></x-td.field>
            <x-td.field label="About (record number)" for="f-subject"><x-td.input name="subject_id" id="f-subject" type="number" min="1" :value="$filters['subject_id'] ?? ''" /></x-td.field>
            <x-td.button type="submit" icon="funnel">Filter</x-td.button>
        </form>

        @include('ops.partials.audit-table', ['entries' => $entries, 'caption' => 'Audit entries, newest first', 'filtered' => $filters !== []])
        @if ($entries->hasPages())<x-td.pagination :paginator="$entries" label="Audit pages" />@endif
    </div>
</x-layouts::operations>
