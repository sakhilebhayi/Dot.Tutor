{{-- The kit's verification badge for a marketplace verification level value (or null when none was assigned). --}}
@php
    $short = [
        'peer_student_tutor' => 'peer',
        'independent_tutor' => 'independent',
        'verified_qualified_tutor' => 'qualified',
        'professional_educator' => 'professional',
        'institution_affiliated' => 'institution',
    ][$level ?? ''] ?? null;
@endphp
@if ($short)
    <x-td.verify-badge status="verified" :level="$short" />
@else
    <x-td.verify-badge :status="($reviewing ?? false) ? 'review' : 'self'" />
@endif
