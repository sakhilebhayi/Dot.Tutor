{{-- R-54: the claim page, in the Reading Room. Shown when a Dot.Tutor account already uses the email address of the InfoDot
     sign-in. Nothing on the account is replaced or wiped; the emailed code proves the address. Variables come from the shared package. --}}
<x-guest-layout title="Confirm it is you">
    <x-authentication-card title="Confirm it is you">
        <x-slot name="logo">
            <x-authentication-card-logo />
        </x-slot>

        <div class="td-claim">
            <p class="td-claim__lede">A Dot.Tutor account already uses <strong>{{ $maskedEmail }}</strong>. We sent a {{ $codeLength }}-digit code to that address. Enter it to connect the two. Your password, two-step sign-in, sessions and everything on the account stay as they are.</p>

            @if (session('dotsso_status'))
                <x-td.notice tone="success">{{ session('dotsso_status') }}</x-td.notice>
            @endif

            <form method="POST" action="{{ $verifyUrl }}" class="td-claim__form" novalidate>
                @csrf
                <x-td.field label="Code from the email" for="code" :hint="'It works for '.$minutes.' minutes and once. You have '.$maxAttempts.' tries.'">
                    <x-td.input name="code" id="code" type="text" inputmode="numeric" pattern="[0-9]*" autocomplete="one-time-code"
                                maxlength="{{ $codeLength }}" required autofocus :hint="true" class="td-claim__code" />
                </x-td.field>
                <x-td.button type="submit" variant="primary" block>Confirm and continue</x-td.button>
            </form>

            <div class="td-claim__aside">
                <form method="POST" action="{{ $resendUrl }}">@csrf<x-td.button type="submit" variant="quiet">Send a new code</x-td.button></form>
                <form method="POST" action="{{ $cancelUrl }}">@csrf<x-td.button type="submit" variant="quiet">Cancel</x-td.button></form>
            </div>
        </div>
    </x-authentication-card>
</x-guest-layout>
