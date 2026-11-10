{{-- Jetstream's sign-in card, in the Dot.Tutor system. --}}
<div class="td-auth">
    {{ $logo }}
    <div {{ $attributes->class(['td-auth__card']) }}>
        {{ $slot }}
    </div>
</div>
