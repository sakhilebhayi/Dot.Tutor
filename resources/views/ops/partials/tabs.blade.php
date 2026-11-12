{{-- Section tabs for the command centre: only tabs this operator's roles can open are shown.
     items: list of ['label', 'route', 'params' => [], 'count' => int|null]; label: the tab list's name. --}}
@php
    $access = app(\App\Domain\Ops\Access\OpsAccess::class);
    $visible = collect($items)->filter(fn ($t) => $access->allowsRoute(request()->user(), $t['route']))
        ->map(fn ($t) => ['label' => $t['label'], 'href' => route($t['route'], $t['params'] ?? []), 'current' => request()->routeIs($t['route']), 'count' => $t['count'] ?? null])
        ->values()->all();
@endphp
@if (count($visible) > 1)
    <x-td.tabs :items="$visible" :label="$label" class="mb-5" />
@endif
