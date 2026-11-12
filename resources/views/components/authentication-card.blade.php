{{-- Jetstream's sign-in card, in the Dot.Tutor system. `title` is the page's one h1 (R-47 a11y D-1702). --}}
@props(['title' => null])
<div class="td-auth">
    {{ $logo }}
    <div {{ $attributes->class(['td-auth__card']) }}>
        @if ($title)
            <h1 class="td-auth__title">{{ $title }}</h1>
        @endif
        {{ $slot }}
    </div>
</div>
