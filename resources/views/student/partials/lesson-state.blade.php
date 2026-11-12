{{-- A booking or seat state in words with a glyph (never colour alone). $status: the stored status value. --}}
@php
    [$text, $icon, $tone] = match ($status) {
        'awaiting_guardian' => ['Waiting for your guardian', 'hourglass', 'review'],
        'pending' => ['Waiting for the tutor', 'hourglass', 'review'],
        'awaiting_payment' => ['Pay to confirm', 'circle-alert', 'lapsed'],
        'confirmed' => ['Confirmed', 'circle-check', 'verified'],
        'in_progress' => ['Happening now', 'circle-dashed', 'verified'],
        'completed', 'attended' => ['Done', 'check', 'peer'],
        'cancelled' => ['Cancelled', 'x', 'self'],
        'no_show' => ['Missed', 'circle-alert', 'self'],
        'disputed' => ['Under review', 'scale', 'review'],
        'expired' => ['Lapsed', 'x', 'self'],
        default => [ucfirst(str_replace('_', ' ', (string) $status)), 'info', 'self'],
    };
@endphp
<span class="td-badge td-badge--{{ $tone }}"><x-td.icon :name="$icon" />{{ $text }}</span>
