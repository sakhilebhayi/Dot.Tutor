{{-- Cycles the theme: your device's setting, light, dark (resources/js/ds/theme.js). Stored on this device only. --}}
<button type="button" {{ $attributes->class(['td-btn', 'td-btn--quiet', 'td-btn--icon', 'td-theme-switch']) }} data-td-theme-switch aria-label="Theme: follows your device. Change theme">
    <x-td.icon name="monitor" class="td-icon--system" />
    <x-td.icon name="sun" class="td-icon--light" />
    <x-td.icon name="moon" class="td-icon--dark" />
</button>
