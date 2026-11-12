{{-- Every learner screen: the ds learner shell with the reading step decided by LearnerContext (D-1101).
     <x-student.shell title="..."> page </x-student.shell>. step overrides the person's step (only for previews).
     A signed-out visitor (only the public find page and storefronts let one in, D-2003) gets the public shell, with
     description as the meta description. --}}
@props(['title' => null, 'step' => null, 'livewire' => false, 'description' => null])
@guest
    <x-layouts::public :title="$title" :description="$description">
        <div class="td-wrap st st-public" data-st-step="standard">
            {{ $slot }}
        </div>
    </x-layouts::public>
@else
    @php
        $resolvedStep = $step ?? app(\App\Domain\Student\Services\LearnerContext::class)->step(auth()->user());
    @endphp
    <x-layouts::learner :title="$title" :step="$resolvedStep" :livewire="$livewire">
        <div class="st" data-st-step="{{ $resolvedStep }}">
            @if (is_string(session('status')) && session('status') !== '')
                {{-- Domain controllers flash "status" after a form; the ds toasts read other keys, so it shows here. --}}
                <x-td.notice tone="success" role="status">{{ session('status') }}</x-td.notice>
            @endif
            {{ $slot }}
        </div>
    </x-layouts::learner>
@endguest
