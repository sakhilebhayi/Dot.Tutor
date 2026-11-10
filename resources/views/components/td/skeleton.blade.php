{{-- Loading placeholder in the shape of what is coming: lines of text, or a block of a given height. --}}
@props(['lines' => 3, 'height' => null])
<div {{ $attributes->merge(['class' => 'grid gap-2']) }} aria-hidden="true">
    @if ($height)
        <span class="td-skeleton" style="height: {{ $height }}"></span>
    @else
        @for ($i = 0; $i < $lines; $i++)
            <span class="td-skeleton" style="width: {{ $i === $lines - 1 ? 60 : 100 }}%"></span>
        @endfor
    @endif
</div>
