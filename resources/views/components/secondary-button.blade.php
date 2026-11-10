<button {{ $attributes->merge(['type' => 'button', 'class' => 'td-btn']) }}>
    {{ $slot }}
</button>
