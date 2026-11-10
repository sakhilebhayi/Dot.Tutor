{{-- Readouts that share edges. Real figures only: a value the product measured, with what it counts.
     items: list of ['label' => , 'value' => (string or HtmlString, e.g. rendered x-td.money), 'hint' => (optional)].
     An unknown value is printed as a dash with a hint that says why, never as zero. --}}
@props(['items' => [], 'label' => null])
<dl {{ $attributes->class(['td-stats']) }} @if ($label) aria-label="{{ $label }}" @endif>
    @foreach ($items as $item)
        <div>
            <dt>{{ $item['label'] }}</dt>
            <dd>{{ $item['value'] ?? '—' }}</dd>
            @isset($item['hint'])<dd class="td-stats__hint">{{ $item['hint'] }}</dd>@endisset
        </div>
    @endforeach
</dl>
