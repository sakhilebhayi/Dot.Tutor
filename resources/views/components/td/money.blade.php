{{-- Money from integer minor units (docs/R47_ARCHITECTURE.md, Money). Never pass a float.
     One display format for the whole app: App\Domain\Money\Money::format() ("R1 250.00", symbol against the figure).
     per: an optional unit printed after the amount ("hour", "lesson", "month"). --}}
@props(['minor', 'currency' => 'ZAR', 'per' => null])
@php($text = \App\Domain\Money\Money::of((int) $minor, $currency ?: null)->format())
<span {{ $attributes->class(['td-money']) }} data-minor="{{ (int) $minor }}" data-currency="{{ strtoupper($currency ?: \App\Domain\Money\Money::defaultCurrency()) }}">{{ $text }}@if ($per)<span class="td-money__unit"> per {{ $per }}</span>@endif</span>
