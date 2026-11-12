{{-- A tutor's verification as the marketplace states it ($verification from TutorPresenter::verification).
     Uses the ds badge; "Not yet verified" when no level was assigned after a review. --}}
@php
    $map = ['peer_student_tutor' => 'peer', 'independent_tutor' => 'independent', 'verified_qualified_tutor' => 'qualified', 'professional_educator' => 'professional', 'institution_affiliated' => 'institution'];
    $level = $verification['level'] ?? null;
@endphp
@if ($level && isset($map[$level]))
    <x-td.verify-badge status="verified" :level="$map[$level]" title="{{ $verification['description'] ?? '' }}" />
@elseif (($verification['state'] ?? null) === 'checked')
    {{-- A verified record, no level yet (fix round D-2302): one status, not "Not yet verified" over a verified record. --}}
    <span class="td-badge td-badge--review" title="{{ $verification['description'] ?? '' }}"><x-td.icon name="hourglass" />{{ $verification['label'] }}</span>
@else
    <span class="td-badge td-badge--self" title="{{ $verification['description'] ?? '' }}"><x-td.icon name="circle-dashed" />{{ $verification['label'] ?? 'Not yet verified' }}</span>
@endif
