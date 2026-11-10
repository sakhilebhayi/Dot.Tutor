{{-- Tutor verification, stated exactly (brief section 16). Show "verified" only after a recorded review.
     status: verified | review | self | lapsed | rejected.
     level: peer | independent | qualified | professional | institution.
     A verified peer tutor stays neutral grey: it must never look like a professional credential. --}}
@props(['status' => 'self', 'level' => null])
@php
    $levels = [
        'peer' => 'student peer tutor',
        'independent' => 'independent tutor',
        'qualified' => 'qualified tutor',
        'professional' => 'professional educator',
        'institution' => 'institution-affiliated educator',
    ];
    $noun = $levels[$level] ?? 'tutor';
    [$text, $icon, $tone] = match ($status) {
        'verified' => ['Verified '.$noun, 'badge-check', $level === 'peer' ? 'peer' : 'verified'],
        'review' => [ucfirst($noun).', verification in review', 'hourglass', 'review'],
        'lapsed' => [ucfirst($noun).', verification lapsed', 'circle-alert', 'lapsed'],
        'rejected' => [ucfirst($noun).', not verified', 'circle-alert', 'rejected'],
        default => [ucfirst($noun).', self-declared, not verified', 'circle-dashed', 'self'],
    };
@endphp
<span {{ $attributes->class(['td-badge', 'td-badge--'.$tone]) }}>
    <x-td.icon :name="$icon" />{{ $text }}
</span>
