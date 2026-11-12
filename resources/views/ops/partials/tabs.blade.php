{{-- Section tabs for the command centre: only tabs this operator's roles can open are shown.
     items: list of ['label', 'route', 'params' => [], 'count' => int|null]; label: the tab list's name.
     The current tab is the exact route, or else the tab whose route is the longest prefix of the current one, so a
     detail page (an incident, a report, a person) keeps its section's tab marked (D-2203). --}}
@php
    $access = app(\App\Domain\Ops\Access\OpsAccess::class);
    $visible = collect($items)->filter(fn ($t) => $access->allowsRoute(request()->user(), $t['route']))->values();
    $currentName = (string) request()->route()?->getName();
    $score = fn ($t) => $currentName === $t['route'] ? PHP_INT_MAX : (str_starts_with($currentName, $t['route'].'.') ? strlen($t['route']) : -1);
    $best = $visible->map($score)->max();
    $visible = $visible->map(fn ($t) => ['label' => $t['label'], 'href' => route($t['route'], $t['params'] ?? []), 'current' => $best >= 0 && $score($t) === $best, 'count' => $t['count'] ?? null])->all();
@endphp
@if (count($visible) > 1)
    <x-td.tabs :items="$visible" :label="$label" class="mb-5" />
@endif
