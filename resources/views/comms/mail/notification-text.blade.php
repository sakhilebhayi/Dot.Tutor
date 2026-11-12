{{ $heading }}

{{ $body }}
@if ($actionUrl)

{{ $actionLabel ?? 'Open in Dot.Tutor' }}: {{ $actionUrl }}
@endif
@if ($ics)

The attached calendar file adds this lesson to your calendar.
@endif

--
You received this because of your "{{ $categoryLabel }}" notifications on Dot.Tutor.
@if ($mandatory)
These cannot be switched off.
@else
Change this in your notification settings: {{ $settingsUrl }}
@endif
