<button {{ $attributes->merge(['type' => 'button', 'class' => 'td-btn td-btn--danger']) }}>
    {{ $slot }}
</button>
