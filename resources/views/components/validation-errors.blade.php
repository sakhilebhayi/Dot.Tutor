@if ($errors->any())
    <div {{ $attributes->merge(['class' => 'td-notice td-notice--danger']) }} role="alert">
        <x-td.icon name="circle-alert" />
        <div class="td-notice__body">
            <p class="td-notice__title">{{ __('Please check the form.') }}</p>
            <ul class="m-0 ps-5">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    </div>
@endif
