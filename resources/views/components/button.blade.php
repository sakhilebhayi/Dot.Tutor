<button {{ $attributes->merge(['type' => 'submit', 'class' => 'td-btn td-btn--primary']) }}>
    {{ $slot }}
</button>
