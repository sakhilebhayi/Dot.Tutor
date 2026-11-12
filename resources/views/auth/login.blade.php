<x-guest-layout title="Sign in" description="Sign in to Dot.Tutor to book lessons, join your rooms and see your progress.">
    <x-authentication-card title="Sign in to Dot.Tutor">
        <x-slot name="logo">
            <x-authentication-card-logo />
        </x-slot>


        @php $infodotLaunch = \App\Support\InfoDotSignIn::launchUrl(); @endphp

        <x-validation-errors class="mb-4" />

        @session('status')
            <div class="mb-4 font-medium text-sm text-emerald-600">
                {{ $value }}
            </div>
        @endsession

        {{-- InfoDot is the sign-in for the Dot ecosystem (fix round D-2301). The button appears only when the launch page
             and the service secret are configured, so it never leads nowhere. --}}
        @if ($infodotLaunch)
            <div class="td-auth__sso">
                <x-td.button variant="primary" :href="$infodotLaunch" icon-end="arrow-right" block>Continue with InfoDot</x-td.button>
                <p class="td-auth__or">Or sign in with a Dot.Tutor password</p>
            </div>
        @endif

        <form method="POST" action="{{ route('login') }}">
            @csrf

            <div>
                <x-label for="email" value="{{ __('Email') }}" />
                <x-input id="email" class="block mt-1 w-full" type="email" name="email" :value="old('email')" required autocomplete="username" />
            </div>

            <div class="mt-4">
                <x-label for="password" value="{{ __('Password') }}" />
                <x-input id="password" class="block mt-1 w-full" type="password" name="password" required autocomplete="current-password" />
            </div>

            <div class="block mt-4">
                <label for="remember_me" class="flex items-center">
                    <x-checkbox id="remember_me" name="remember" />
                    <span class="ms-2 text-sm text-[var(--moss)]">{{ __('Remember me') }}</span>
                </label>
            </div>

            <div class="flex items-center justify-end mt-4">
                @if (Route::has('password.request'))
                    <a class="underline inline-block py-1 text-sm text-[var(--ink)] hover:text-[var(--leaf)] rounded-md focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-[var(--gold)]" href="{{ route('password.request') }}">
                        {{ __('Forgot your password?') }}
                    </a>
                @endif

                <x-button class="ms-4">
                    {{ __('Sign in') }}
                </x-button>
            </div>
        </form>

        <p class="td-auth__new">
            <strong>New here?</strong>
            @if ($infodotLaunch)
                Your Dot.Tutor account is made from your InfoDot account the first time you continue with InfoDot.
            @else
                Dot.Tutor accounts come from InfoDot, the Dot ecosystem's sign-in; there is no separate sign-up on this page.
            @endif
        </p>
    </x-authentication-card>
</x-guest-layout>
