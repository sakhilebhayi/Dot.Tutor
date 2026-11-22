{{-- R-54: every sign-in failure ends here, in words, with one next step and no automatic redirect. The two "no account"
     reasons are rewritten for Dot.Tutor: accounts are not opened for unknown people (child safeguarding), so the page says
     who can get in and what to do. --}}
@php
    $noAccount = in_array($code, ['not_provisioned', 'provision_refused'], true);
    if ($noAccount) {
        $headline = 'There is no Dot.Tutor account for this email yet';
        $body = 'Dot.Tutor does not open accounts from a sign-in alone. A parent or guardian gets in through the invitation a learner sends them, and everyone else is added by the Dot.Tutor team. If a learner invited you, open the link in that email again. If you are a learner, ask your parent or guardian to help you.';
    }
@endphp
<x-guest-layout title="{{ $headline }}">
    <x-authentication-card :title="$headline">
        <x-slot name="logo">
            <x-authentication-card-logo />
        </x-slot>

        <div class="td-claim" role="alert">
            <p class="td-claim__lede">{{ $body }}</p>
            <div class="td-claim__actions">
                @if ($retry)<x-td.button variant="primary" :href="$retryUrl" block>Start again</x-td.button>@endif
                <x-td.button :href="$homeUrl" block>Back to Dot.Tutor</x-td.button>
            </div>
            <p class="td-claim__ref">Reference: {{ $code }}</p>
        </div>
    </x-authentication-card>
</x-guest-layout>
