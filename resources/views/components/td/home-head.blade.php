{{-- The head of a role home (R-51): the original logo, one true sentence about this person's state (the page's h1), one
     optional sentence, and exactly one primary action (data-primary-action). Everything in the slot is quiet.
     headline: the h1. sub: one sentence or null. primary: ['label' => , 'href' => , 'icon' => optional sprite name].
     No other person's name or details belong here: learners may be minors. --}}
@props(['headline', 'sub' => null, 'primary'])
<section {{ $attributes->class(['hm-head']) }} aria-labelledby="home-title">
    <img class="hm-head__logo" src="{{ asset('dot_tutor.png') }}" alt="Dot.Tutor" width="1181" height="886">
    <div>
        <h1 id="home-title">{{ $headline }}</h1>
        @if (filled($sub))<p class="hm-head__sub">{{ $sub }}</p>@endif
    </div>
    <div class="hm-head__act">
        {{ $slot }}
        <x-td.button variant="primary" size="lg" :href="$primary['href']" :icon="$primary['icon'] ?? null" icon-end="arrow-right" data-primary-action>{{ $primary['label'] }}</x-td.button>
    </div>
    <x-td.shelf variant="band" />
</section>
