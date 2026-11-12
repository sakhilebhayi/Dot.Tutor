<x-guest-layout title="Reset your password" description="Ask for a link to choose a new Dot.Tutor password.">
    <x-authentication-card title="Reset your password">
        <x-slot name="logo">
            <x-authentication-card-logo />
        </x-slot>


        <div class="mb-4 text-sm text-[var(--moss)]">
            Enter the email address on your Dot.Tutor account and we will email you a link to choose a new password. If you sign in through InfoDot, reset your password on InfoDot instead.
        </div>

        @session('status')
            <div class="mb-4 font-medium text-sm text-emerald-600">
                {{ $value }}
            </div>
        @endsession

        <x-validation-errors class="mb-4" />

        <form method="POST" action="{{ route('password.email') }}">
            @csrf

            <div class="block">
                <x-label for="email" value="{{ __('Email') }}" />
                <x-input id="email" class="block mt-1 w-full" type="email" name="email" :value="old('email')" required autocomplete="username" />
            </div>

            <div class="flex items-center justify-end mt-4">
                <x-button>
                    Email me a reset link
                </x-button>
            </div>
        </form>
    </x-authentication-card>
</x-guest-layout>
