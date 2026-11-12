{{-- Tabs. With links (items have href) it is navigation and the state lives in the URL: prefer this.
     With panels (items have panel ids), it is an ARIA tablist driven by resources/js/ds/tabs.js; put each
     panel in an x-td.tab-panel with the matching id.
     items: list of ['label' => , 'href' => | 'panel' => , 'current' => bool, 'count' => int|null]. --}}
@props(['items' => [], 'label'])
@if (collect($items)->every(fn ($item) => isset($item['href'])))
    <nav {{ $attributes->class(['td-tabs']) }} aria-label="{{ $label }}">
        @foreach ($items as $item)
            <a class="td-tab" href="{{ $item['href'] }}" @if ($item['current'] ?? false) aria-current="page" @endif>
                {{ $item['label'] }}
                @isset($item['count'])<span class="td-tab__count">{{ $item['count'] }}</span>@endisset
            </a>
        @endforeach
    </nav>
@else
    <div {{ $attributes->class(['td-tabs']) }} role="tablist" aria-label="{{ $label }}" data-td-tabs>
        @foreach ($items as $item)
            <button type="button" class="td-tab" role="tab" id="{{ $item['panel'] }}-tab" aria-controls="{{ $item['panel'] }}"
                aria-selected="{{ ($item['current'] ?? $loop->first) ? 'true' : 'false' }}" tabindex="{{ ($item['current'] ?? $loop->first) ? '0' : '-1' }}">
                {{ $item['label'] }}
                @isset($item['count'])<span class="td-tab__count">{{ $item['count'] }}</span>@endisset
            </button>
        @endforeach
    </div>
@endif
