{{-- The toast region, once per shell. Session flash messages (flash.banner from Jetstream, status, success,
     error) become toasts. Script adds dismiss and timing; without script they stay as plain status lines.
     A refused form also gets one error toast with the first validation message: a rule refused by a service
     (for example "a check cannot be cleared without evidence") has no field of its own to show it, and was
     otherwise silent (QA). Field messages still show next to their fields. --}}
@php
    $validation = isset($errors) && $errors->any() ? 'Not saved: '.$errors->first() : null;
    $flashes = array_filter([
        ['message' => session('flash.banner'), 'tone' => session('flash.bannerStyle') === 'danger' ? 'error' : 'success'],
        ['message' => session('success'), 'tone' => 'success'],
        ['message' => session('error') ?: $validation, 'tone' => 'error'],
    ], fn ($flash) => is_string($flash['message']) && $flash['message'] !== '');
@endphp
<div class="td-toasts" data-td-toasts aria-live="polite">
    @foreach ($flashes as $flash)
        <div class="td-toast td-toast--{{ $flash['tone'] }}" role="{{ $flash['tone'] === 'error' ? 'alert' : 'status' }}" data-td-toast="{{ $flash['message'] }}" data-td-tone="{{ $flash['tone'] }}">
            <p class="td-toast__text" style="margin:0">{{ $flash['message'] }}</p>
        </div>
    @endforeach
</div>
