{{-- A language as people know it: its own name first (isiZulu, Afrikaans), the English name for others, the
     BCP 47 code, and the role it plays here. role: teaches | understands | learning | interface | prefers | null.
     verified: only after a real check of the tutor's teaching language (docs/R47_ARCHITECTURE.md, Languages).
     match: true when this language is the one the learner asked for. pressable: renders a toggle button. --}}
@props(['code', 'name', 'endonym' => null, 'role' => null, 'verified' => false, 'match' => false, 'pressable' => false, 'pressed' => false])
@php
    $roles = ['teaches' => 'Teaches in', 'understands' => 'Understands', 'learning' => 'Learning', 'interface' => 'Interface', 'prefers' => 'Prefers'];
    $own = $endonym ?: $name;
    $classes = ['td-chip', 'td-chip--verified' => $verified, 'td-chip--match' => $match];
@endphp
@if ($pressable)
<button type="button" {{ $attributes->class($classes) }} aria-pressed="{{ $pressed ? 'true' : 'false' }}" lang="{{ $code }}">
@else
<span {{ $attributes->class($classes) }}>
@endif
    @if ($role && isset($roles[$role]))<span class="td-chip__role" lang="en">{{ $roles[$role] }}</span>@endif
    <span lang="{{ $code }}">{{ $own }}</span>
    @if ($endonym && $endonym !== $name)<span class="td-sr" lang="en">({{ $name }})</span>@endif
    <span class="td-chip__code" aria-hidden="true">{{ $code }}</span>
    @if ($verified)<x-td.icon name="badge-check" label="Teaching language verified" />@endif
@if ($pressable)
</button>
@else
</span>
@endif
