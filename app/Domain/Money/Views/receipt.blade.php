@php use App\Domain\Money\Money; $s = $receipt->snapshot; $cur = $s['currency']; $op = $s['operator'] ?? []; @endphp
<x-app-layout>
@include('money::partials.styles')
<div class="t-page m-receipt">
    <h1 class="t-h1">Receipt <span style="white-space:nowrap;">{{ $receipt->number }}</span></h1>
    <p class="t-lede m-noprint">Use your browser's print command to print this receipt or save it as a PDF.</p>

    <section class="t-panel" aria-label="Receipt">
        <div class="t-grid2">
            <div>
                @if(isset($op['trading_name']))<p class="t-h2">{{ $op['trading_name'] }}</p>@endif
                @if(isset($op['legal_name']))<p class="t-hint" style="margin:0;">{{ $op['legal_name'] }}@if(isset($op['registration_number'])), reg. {{ $op['registration_number'] }}@endif</p>@endif
                @if(isset($op['address']))<p class="t-hint" style="margin:0;">{{ $op['address'] }}</p>@endif
                @if(isset($op['vat_number']))<p class="t-hint" style="margin:0;">VAT number {{ $op['vat_number'] }}</p>@endif
                @if(isset($op['support_email']))<p class="t-hint" style="margin:0;">{{ $op['support_email'] }}</p>@endif
            </div>
            <dl class="m-sum" style="margin:0;">
                <dt>Receipt</dt><dd>{{ $receipt->number }}</dd>
                <dt>Date</dt><dd>{{ $receipt->issued_at->format('j M Y H:i') }}</dd>
                @if($s['buyer']['name'] ?? null)<dt>Billed to</dt><dd style="font-family:inherit;">{{ $s['buyer']['name'] }}</dd>@endif
            </dl>
        </div>

        <div style="overflow-x:auto;margin-top:1.25rem;" role="region" aria-label="Items" tabindex="0">
            <table class="t-table m-lines">
                <thead><tr><th scope="col">Item</th><th scope="col" class="m-hide-sm">Tutor</th><th scope="col" class="m-num m-hide-sm">Price</th><th scope="col" class="m-num">Paid</th></tr></thead>
                <tbody>
                @foreach($s['lines'] as $line)
                    <tr><td>{{ $line['title'] }}</td><td class="m-hide-sm">{{ $line['seller'] ?? 'Dot.Tutor' }}</td><td class="m-num m-hide-sm">{{ Money::of($line['price_minor'], $cur)->format() }}</td><td class="m-num">{{ Money::of($line['paid_minor'], $cur)->format() }}</td></tr>
                @endforeach
                </tbody>
            </table>
        </div>

        <dl class="m-sum">
            <dt>Subtotal</dt><dd>{{ Money::of($s['subtotal_minor'], $cur)->format() }}</dd>
            @if($s['discount_minor'])<dt>Discount{{ $s['coupon'] ? ' ('.$s['coupon'].')' : '' }}</dt><dd>-{{ Money::of($s['discount_minor'], $cur)->format() }}</dd>@endif
            @if($s['credit_minor'])<dt>Promotional credit</dt><dd>-{{ Money::of($s['credit_minor'], $cur)->format() }}</dd>@endif
            <dt class="m-total">Total paid</dt><dd class="m-total">{{ Money::of($s['total_minor'], $cur)->format() }}</dd>
            @if($s['vat'])<dt>Includes VAT at {{ rtrim(rtrim(number_format($s['vat']['rate_percent'], 2), '0'), '.') }}%</dt><dd>{{ Money::of($s['vat']['included_minor'], $cur)->format() }}</dd>@endif
            <dt>Paid with</dt><dd style="font-family:inherit;">{{ $s['payment']['method'] }}</dd>
            @if($s['payment']['reference'] ?? null)<dt>Reference</dt><dd>{{ $s['payment']['reference'] }}</dd>@endif
        </dl>
    </section>
</div>
</x-app-layout>
