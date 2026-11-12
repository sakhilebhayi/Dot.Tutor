{{-- The room's academic address: stage binding colour (data, not decoration), level, curriculum, audience. --}}
@php($stage = $room->level?->stage?->code)
<div class="flex flex-wrap items-center gap-2">
    @if ($stage)<x-td.pill kind="stage" :stage="$stage">{{ $room->level?->stage?->name }}</x-td.pill>@endif
    <x-td.pill kind="level" :stage="$stage">{{ $room->level?->name }}</x-td.pill>
    <x-td.pill>{{ $room->curriculum?->short_name ?: $room->curriculum?->name }}</x-td.pill>
    <span class="text-sm text-td-ink-2">{{ $room->audienceLabel() }}</span>
</div>
