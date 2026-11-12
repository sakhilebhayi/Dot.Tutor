{{-- Signed in: the student UI's page (D-1114). Signed out: this guest page. --}}
@auth
@include('student.locale')
@else
<x-guest-layout>
<div class="t-page" style="max-width:40rem;margin:0 auto;padding:2rem 1rem;">
    <h1 class="t-h1">{{ __('academic.locale.title') }}</h1>
    <p class="t-lede">{{ __('academic.locale.lede') }}</p>

    @if(session('status'))<div class="t-flash" role="status">{{ session('status') }}</div>@endif

    <form method="POST" action="{{ route('academic.locale.update') }}" class="t-panel">
        @csrf
        <fieldset style="border:0;padding:0;margin:0 0 1rem;">
            <legend class="t-label">{{ __('academic.locale.choose') }}</legend>
            @foreach($options as $option)
                <label style="display:flex;gap:.6rem;align-items:flex-start;padding:.5rem 0;border-bottom:1px solid rgba(127,127,127,.2);">
                    <input type="radio" name="locale" value="{{ $option['code'] }}" @checked($current === $option['code']) style="margin-top:.25rem;">
                    <span>
                        <strong lang="{{ $option['code'] }}">{{ $option['name_native'] }}</strong>
                        @if($option['name_native'] !== $option['name_en'])<span> ({{ $option['name_en'] }})</span>@endif
                        <br>
                        <small data-coverage="{{ $option['status'] }}">
                            @if($option['status'] === 'complete')
                                {{ __('academic.locale.complete') }}
                            @elseif($option['status'] === 'partial')
                                {{ __('academic.locale.partial') }}
                            @else
                                {{ __('academic.locale.none') }}
                            @endif
                            @if($option['provenance'] === 'machine_unreviewed')
                                &middot; {{ __('academic.locale.machine_note') }}
                            @elseif($option['provenance'] === 'reviewed')
                                &middot; {{ __('academic.locale.reviewed_note') }}
                            @endif
                        </small>
                    </span>
                </label>
            @endforeach
        </fieldset>
        @error('locale')<div class="t-err" role="alert">{{ $message }}</div>@enderror
        <button type="submit" class="t-btn t-btn-primary">{{ __('academic.locale.apply') }}</button>
    </form>
</div>
</x-guest-layout>
@endauth
