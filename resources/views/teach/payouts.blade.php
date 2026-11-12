@php
    $m = fn ($money) => \App\Domain\Teach\Support\TutorContext::money($money);
    $statusWords = ['draft' => 'Drafted, waiting to be paid', 'processing' => 'Being transferred', 'paid' => 'Paid', 'failed' => 'Failed, earnings return to the next run'];
@endphp
<x-layouts::workspace title="Payouts">
    <x-td.page-head title="Payouts" :lede="'Next payout run '.$glance['next_run']->format('l j F, H:i').'. '.($glance['blocker'] ?? 'Your available earnings will be drafted into it.')" />

    <x-td.stat-strip label="Payout position" class="mb-6" :items="[
        ['label' => 'Available now', 'value' => $m($glance['available']), 'hint' => 'Minimum per payout '.strip_tags((string) $m($glance['minimum']))],
        ['label' => 'Pending', 'value' => $m($glance['pending']), 'hint' => 'Not yet available'],
        ['label' => 'Paid out so far', 'value' => $m($glance['paid_out'])],
    ]" />

    <div class="td-panels td-panels--2">
        <x-td.panel title="Your payouts" flush>
            @if ($payouts->isEmpty())
                <div class="p-5"><x-td.state kind="empty" title="No payouts yet">
                    How it works: on each payout run, available earnings of at least {{ strip_tags((string) $m($glance['minimum'])) }} are drafted into one payout to the bank account below. Finance then pays it{{ $transfers ? ' by Paystack transfer' : ' from the platform bank account' }} and marks it paid, and the statement lists every earning in it.
                </x-td.state></div>
            @else
                <x-td.table caption="Your payouts" caption-hidden dense stack>
                    <thead><tr><th scope="col">Reference</th><th scope="col">Created</th><th scope="col" class="is-num" data-stack="key">Amount</th><th scope="col">Status</th><th scope="col">Paid</th></tr></thead>
                    <tbody>
                        @foreach ($payouts as $p)
                            <tr>
                                <th scope="row"><a class="td-link" href="{{ route('teach.payouts.show', $p) }}">{{ $p->reference }}</a></th>
                                <td class="tabular-nums">{{ $p->created_at->copy()->timezone($tz)->format('j M Y') }}</td>
                                <td class="is-num">{{ $m(\App\Domain\Money\Money::of($p->amount_minor, $p->currency)) }}</td>
                                <td>{{ $statusWords[$p->status] ?? $p->status }}</td>
                                <td class="tabular-nums">{{ $p->paid_at ? $p->paid_at->copy()->timezone($tz)->format('j M Y') : '—' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </x-td.table>
                @if ($payouts->hasPages())<div class="p-4"><x-td.pagination :paginator="$payouts" label="Payout pages" /></div>@endif
            @endif
        </x-td.panel>

        <x-td.panel title="Bank details" id="bank">
            @if ($account)
                <p class="mt-0">Paying into <strong>{{ $account->bank_name }}</strong>, account ending <strong class="tabular-nums">{{ $account->account_last4 }}</strong> ({{ $account->account_type ?? 'personal' }}).</p>
                <p class="text-sm text-td-ink-2">The full number is stored encrypted and never shown again. Saving new details replaces these.</p>
            @else
                <p class="mt-0">Add the account your payouts go to. Without it, available earnings carry forward.</p>
            @endif
            @if ($profile->isApproved())
                <form method="POST" action="{{ route('teach.payouts.account') }}" class="grid gap-4" autocomplete="off">
                    @csrf @method('PUT')
                    <x-td.field label="Account holder" for="holder_name"><x-td.input name="holder_name" maxlength="120" required autocomplete="off" /></x-td.field>
                    <x-td.field label="Bank" for="bank_name"><x-td.input name="bank_name" maxlength="80" required :value="$account?->bank_name" /></x-td.field>
                    <div class="grid gap-4 sm:grid-cols-2">
                        <x-td.field label="Branch code" for="bank_code"><x-td.input name="bank_code" maxlength="20" required inputmode="numeric" :value="$account?->bank_code" /></x-td.field>
                        <x-td.field label="Account type" for="account_type"><x-td.select name="account_type" :options="['personal' => 'Personal', 'business' => 'Business']" :value="$account?->account_type ?? 'personal'" /></x-td.field>
                    </div>
                    <x-td.field label="Account number" for="account_number"><x-td.input name="account_number" maxlength="24" required inputmode="numeric" autocomplete="off" /></x-td.field>
                    <div><x-td.button type="submit" variant="primary">{{ $account ? 'Replace bank details' : 'Save bank details' }}</x-td.button></div>
                </form>
            @else
                <x-td.state kind="empty" title="Opens after approval">Bank details can be added once an operator approves your profile.</x-td.state>
            @endif
        </x-td.panel>
    </div>
</x-layouts::workspace>
