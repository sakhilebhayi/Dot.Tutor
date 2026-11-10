{{-- Money from integer minor units (docs/R47_ARCHITECTURE.md, Money). Never pass a float.
     per: an optional unit printed after the amount ("hour", "lesson", "month"). --}}
@props(['minor', 'currency' => 'ZAR', 'per' => null])
@php
    $digits = in_array(strtoupper($currency), ['JPY', 'KRW', 'UGX', 'RWF'], true) ? 0 : 2;
    $major = intdiv((int) $minor, 10 ** $digits);
    $fraction = abs((int) $minor) % (10 ** $digits);
    $negative = (int) $minor < 0;
    $symbols = ['ZAR' => 'R', 'USD' => '$', 'EUR' => '€', 'GBP' => '£', 'NAD' => 'N$', 'BWP' => 'P', 'KES' => 'KSh', 'NGN' => '₦'];
    $symbol = $symbols[strtoupper($currency)] ?? strtoupper($currency).' ';
    // South African style: a space between thousands, a full stop before cents.
    $whole = number_format(abs($major), 0, '.', "\u{202F}");
    $text = ($negative ? '−' : '').$symbol.$whole.($digits ? '.'.str_pad((string) $fraction, $digits, '0', STR_PAD_LEFT) : '');
@endphp
<span {{ $attributes->class(['td-money']) }} data-minor="{{ (int) $minor }}" data-currency="{{ strtoupper($currency) }}">{{ $text }}@if ($per)<span class="td-money__unit"> per {{ $per }}</span>@endif</span>
