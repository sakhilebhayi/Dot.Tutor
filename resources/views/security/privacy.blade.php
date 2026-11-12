<x-student.shell title="Your data and account">
    <x-td.page-head title="Your data and account" lede="Take a copy of everything Dot.Tutor holds about you, or close your account." />

    @if (is_string(session('error')) && session('error') !== '')
        <x-td.notice tone="warning" title="Sign in again first">{{ session('error') }}</x-td.notice>
    @endif

    <div class="st-cols st-cols--even">
        <x-td.panel title="Download your data">
            <div class="st-stack">
                <p style="margin:0">One file with your profile, lessons, courses, purchases, messages you sent, posts and settings, as JSON. Passwords, sign-in secrets and full bank account numbers are never included.</p>
                <p class="st-small" style="margin:0">For your protection this needs a sign-in in the last {{ (int) config('security.reauth_minutes', 15) }} minutes. Safeguarding records are given on request after a safeguarding officer has reviewed them.</p>
                <div><x-td.button variant="primary" icon="download" :href="route('security.export')">Download my data</x-td.button></div>
            </div>
        </x-td.panel>

        <x-td.panel title="Close your account">
            <form method="POST" action="{{ route('security.close') }}" class="st-stack">
                @csrf
                <p style="margin:0">You are signed out and cannot sign in to this account again. Your profile, languages, settings and notifications are erased.</p>
                <p class="st-small" style="margin:0">Purchases, receipts, earnings and payouts are kept for as long as tax law asks, under "Closed account". Records a safeguarding officer needs stay with them. Close only after any lesson you booked or teach has ended.</p>
                <x-td.field label="Type CLOSE to confirm" for="confirm">
                    <x-td.input name="confirm" autocomplete="off" required />
                </x-td.field>
                <div><x-td.button type="submit" variant="danger" icon="trash-2">Close my account</x-td.button></div>
            </form>
        </x-td.panel>
    </div>
</x-student.shell>
