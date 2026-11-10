{{-- The one container. Group panels in <div class="td-panels"> so they share edges instead of floating.
     flush=true removes body padding (for tables and lists that run edge to edge). --}}
@props(['title' => null, 'flush' => false, 'headingLevel' => 2])
<section {{ $attributes->class(['td-panel']) }} @if ($title) aria-labelledby="{{ $pid = 'panel-'.\Illuminate\Support\Str::slug($title).'-'.\Illuminate\Support\Str::random(4) }}" @endif>
    @if ($title || isset($actions))
        <div class="td-panel__head">
            @if ($title)<h{{ $headingLevel }} class="td-panel__title" id="{{ $pid }}">{{ $title }}</h{{ $headingLevel }}>@endif
            @isset($actions)<div class="td-page-head__actions">{{ $actions }}</div>@endisset
        </div>
    @endif
    <div @class(['td-panel__body', 'td-panel__body--flush' => $flush])>{{ $slot }}</div>
</section>
