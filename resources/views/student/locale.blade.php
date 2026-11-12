{{-- /language (academic.locale.index) for a signed-in learner (D-1114): the interface language with honest coverage.
     From the academic controller: $options, $current. Guests keep the academic domain's own page. --}}
<x-student.shell :title="__('academic.locale.title')">
    <x-td.page-head :title="__('academic.locale.title')" :lede="__('academic.locale.lede')" />
    <x-td.panel :title="__('academic.locale.choose')">
        <form method="POST" action="{{ route('academic.locale.update') }}" class="st-form">
            @csrf
            <fieldset>
                <legend class="td-sr">{{ __('academic.locale.choose') }}</legend>
                <div class="st-stack st-stack--tight">
                    @foreach ($options as $option)
                        <x-td.check type="radio" name="locale" :value="$option['code']" :checked="$current === $option['code']" id="locale-{{ $option['code'] }}">
                            <strong lang="{{ $option['code'] }}">{{ $option['name_native'] }}</strong>@if ($option['name_native'] !== $option['name_en']) ({{ $option['name_en'] }})@endif
                            <span class="st-small" style="display:block" data-coverage="{{ $option['status'] }}">
                                @if ($option['status'] === 'complete'){{ __('academic.locale.complete') }}@elseif ($option['status'] === 'partial'){{ __('academic.locale.partial') }}@else{{ __('academic.locale.none') }}@endif
                                @if ($option['provenance'] === 'machine_unreviewed') · {{ __('academic.locale.machine_note') }} @elseif ($option['provenance'] === 'reviewed') · {{ __('academic.locale.reviewed_note') }} @endif
                            </span>
                        </x-td.check>
                    @endforeach
                </div>
            </fieldset>
            @error('locale')<p class="td-error"><x-td.icon name="circle-alert" />{{ $message }}</p>@enderror
            <p class="st-small" style="margin:0">This changes the words on screen only. Tutors are matched on the languages you want to be taught in, which you set in your <a class="td-link" href="{{ route('academic.profile.edit') }}">learning profile</a>.</p>
            <div class="st-form__actions"><x-td.button type="submit" variant="primary">{{ __('academic.locale.apply') }}</x-td.button></div>
        </form>
    </x-td.panel>
</x-student.shell>
