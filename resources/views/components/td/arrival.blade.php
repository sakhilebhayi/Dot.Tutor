{{-- The arrival: what a person sees first when InfoDot hands them to Dot.Tutor (R-50). One per role home.
     The original logo file, a welcome from the person's own record, one headline, one or two sentences, and exactly
     one primary action (data-primary-action). Everything else on it is quiet.
     headline: the role's headline (the page's h1). lede: what Dot.Tutor is for, for this role.
     primary: ['label' => , 'href' => , 'icon' => optional sprite name].
     slip: the real record behind the action, or the honest empty state: ['label' => , 'text' => , 'note' => optional].
     greeting: overrides the welcome word ("Hi" in the young step, which reads "Hi Lerato."). Slot: quiet secondary actions.
     No other person's name or details belong here: learners may be minors. --}}
@props(['headline', 'lede', 'primary', 'slip' => null, 'greeting' => 'Welcome'])
@php
    $arrivalUser = auth()->user();
    $arrivalFirst = trim((string) \Illuminate\Support\Str::of((string) $arrivalUser?->name)->before(' '));
    // A personal Jetstream team ("Sipho's Team") is not a company; only a team InfoDot or the person set up is named.
    $arrivalTeam = $arrivalUser?->currentTeam;
    $arrivalCompany = $arrivalTeam && ! $arrivalTeam->personal_team ? $arrivalTeam->name : null;
    $infodot = rtrim((string) config('ecosystem.platforms.infodot.url'), '/');
@endphp
<section {{ $attributes->class(['td-arrival']) }} aria-labelledby="arrival-title">
    <div class="td-arrival__plate">
        <img class="td-arrival__logo" src="{{ asset('dot_tutor.png') }}" alt="Dot.Tutor" width="1181" height="886">
    </div>
    <div class="td-arrival__body">
        <h1 class="td-arrival__title" id="arrival-title">{{ $headline }}</h1>
        <p class="td-arrival__lede">
            <span class="td-arrival__welcome">{{ $greeting }}@if ($arrivalFirst !== ''){{ $greeting === 'Hi' ? ' ' : ', ' }}{{ $arrivalFirst }}@endif{{ $arrivalCompany ? ', from '.$arrivalCompany : '' }}.</span>
            {{ $lede }}
        </p>
        @if ($slip)
            <dl class="td-arrival__slip">
                <dt>{{ $slip['label'] }}</dt>
                <dd>{{ $slip['text'] }}@if (! empty($slip['note']))<span class="td-arrival__note">{{ $slip['note'] }}</span>@endif</dd>
            </dl>
        @endif
        <div class="td-arrival__actions">
            <x-td.button variant="primary" size="lg" :href="$primary['href']" :icon="$primary['icon'] ?? null" icon-end="arrow-right" data-primary-action>{{ $primary['label'] }}</x-td.button>
            @if (! $slot->isEmpty())<div class="td-arrival__more">{{ $slot }}</div>@endif
        </div>
    </div>
    <nav class="td-arrival__back" aria-label="Dot ecosystem">
        <span>Dot.Tutor is part of the Dot ecosystem.</span>
        <a class="td-link" href="{{ $infodot }}/home">InfoDot</a>
        <a class="td-link" href="{{ $infodot }}/platforms">Platforms</a>
    </nav>
</section>
