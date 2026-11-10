{{-- Why a tutor fits a request, one line per dimension the matcher checked (subject, level, language, time,
     budget, verification). reasons: list of ['text' => , 'met' => bool]. Unmet lines are shown, never hidden. --}}
@props(['reasons' => [], 'label' => 'Why this tutor fits'])
<ul {{ $attributes->class(['td-reasons']) }} aria-label="{{ $label }}">
    @foreach ($reasons as $reason)
        <li @class(['is-gap' => ! $reason['met']])>
            <x-td.icon :name="$reason['met'] ? 'check' : 'minus'" />
            <span><span class="td-sr">{{ $reason['met'] ? 'Fits: ' : 'Does not fit: ' }}</span>{{ $reason['text'] }}</span>
        </li>
    @endforeach
</ul>
