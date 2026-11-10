<x-mail::message>
# {{ $heading }}

{{ $body }}

@if ($actionUrl)
<x-mail::button :url="$actionUrl">
{{ $actionLabel ?? 'Open in Dot.Tutor' }}
</x-mail::button>
@endif

@if ($ics)
The attached calendar file adds this lesson to your calendar.
@endif

<x-mail::subcopy>
You received this because of your "{{ $categoryLabel }}" notifications on Dot.Tutor.
@if ($mandatory)
These cannot be switched off.
@else
Change this in your notification settings: {{ $settingsUrl }}
@endif
</x-mail::subcopy>
</x-mail::message>
