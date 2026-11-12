{{-- /money/receipts/{receipt} (money.receipts.show) (D-1113). From the money controller: $receipt (immutable snapshot). --}}
@php use App\Domain\Money\Money; use App\Domain\Student\Support\Times; $s = $receipt->snapshot; $cur = $s['currency']; $op = $s['operator'] ?? []; @endphp
<x-student.shell :title="'Receipt '.$receipt->number">
    <x-td.page-head :title="'Receipt '.$receipt->number" lede="Print this receipt, or choose Save as PDF in the print window.">
        <x-slot:actions>
            {{-- Print through a CSP-safe module (fix round D-2307); without script the note below says how. --}}
            <x-td.button variant="primary" icon="file" data-td-print hidden>Print or save as PDF</x-td.button>
            <x-td.button variant="quiet" icon="arrow-left" :href="route('money.orders')">Purchases</x-td.button>
        </x-slot:actions>
    </x-td.page-head>
    <p class="st-small td-no-print" data-td-print-fallback style="margin:0">Use your browser's print command to print this receipt or save it as a PDF.</p>

    <x-td.panel title="Receipt" flush>
        <div class="td-panel__body st-cols st-cols--even">
            <div class="st-stack st-stack--tight">
                @if (isset($op['trading_name']))<p class="st-title">{{ $op['trading_name'] }}</p>@endif
                {{-- The seller's legal name comes from the operator settings frozen on the receipt (MONEY_OPERATOR_LEGAL_NAME);
                     when it was not set, nothing is put in its place (D-2307). --}}
                @if (isset($op['legal_name']))<p class="st-small" style="margin:0">Sold by <strong>{{ $op['legal_name'] }}</strong>@if (isset($op['registration_number'])), reg. {{ $op['registration_number'] }}@endif</p>@endif
                @if (isset($op['address']))<p class="st-small" style="margin:0">{{ $op['address'] }}</p>@endif
                @if (isset($op['vat_number']))<p class="st-small" style="margin:0">VAT number {{ $op['vat_number'] }}</p>@endif
                @if (isset($op['support_email']))<p class="st-small" style="margin:0">{{ $op['support_email'] }}</p>@endif
            </div>
            <dl class="st-facts">
                <dt>Receipt</dt><dd>{{ $receipt->number }}</dd>
                <dt>Date</dt><dd>{{ Times::stamp($receipt->issued_at) }}</dd>
                @if ($s['buyer']['name'] ?? null)<dt>Billed to</dt><dd>{{ $s['buyer']['name'] }}</dd>@endif
            </dl>
        </div>
        {{-- Rows stack as labelled cards on phones so Paid is never cut off (D-2307, the stack pattern of D-2202). --}}
        <x-td.table caption="Items paid" class="st-receipt-table" stack>
            <thead><tr><th scope="col">Item</th><th scope="col">Tutor</th><th scope="col" class="is-num">Price</th><th scope="col" class="is-num" data-stack="key">Paid</th></tr></thead>
            <tbody>
                @foreach ($s['lines'] as $line)
                    <tr><td>{{ $line['title'] }}</td><td>{{ $line['seller'] ?? 'Dot.Tutor' }}</td><td class="is-num">{{ Money::of($line['price_minor'], $cur)->format() }}</td><td class="is-num">{{ Money::of($line['paid_minor'], $cur)->format() }}</td></tr>
                @endforeach
            </tbody>
        </x-td.table>
        <div class="td-panel__body">
            <dl class="st-facts">
                <dt>Subtotal</dt><dd>{{ Money::of($s['subtotal_minor'], $cur)->format() }}</dd>
                @if ($s['discount_minor'])<dt>Discount{{ $s['coupon'] ? ' ('.$s['coupon'].')' : '' }}</dt><dd>−{{ Money::of($s['discount_minor'], $cur)->format() }}</dd>@endif
                @if ($s['credit_minor'])<dt>Promotional credit</dt><dd>−{{ Money::of($s['credit_minor'], $cur)->format() }}</dd>@endif
                <dt><strong>Total paid</strong></dt><dd><strong>{{ Money::of($s['total_minor'], $cur)->format() }}</strong></dd>
                @if ($s['vat'])<dt>Includes VAT at {{ rtrim(rtrim(number_format($s['vat']['rate_percent'], 2), '0'), '.') }}%</dt><dd>{{ Money::of($s['vat']['included_minor'], $cur)->format() }}</dd>@endif
                <dt>Paid with</dt><dd>{{ $s['payment']['method'] }}</dd>
                @if ($s['payment']['reference'] ?? null)<dt>Reference</dt><dd>{{ $s['payment']['reference'] }}</dd>@endif
            </dl>
        </div>
    </x-td.panel>
</x-student.shell>
