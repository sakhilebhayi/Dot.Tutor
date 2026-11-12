{{-- A one-page promotional sheet a tutor can print or save as PDF (the browser's print to PDF) and hand out or pin
     up. Built only from the tutor's real profile; the verification line says exactly what was checked. --}}
@php
    $money = fn (int $minor, ?string $c = null) => \App\Domain\Teach\Support\TutorContext::money($minor, $c);
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" data-td-step="standard">
<head>
    @include('layouts.partials.head', ['title' => $store['name'].', tutor'])
</head>
<body class="bg-td-ground text-td-ink">
    <main id="main-content" class="mx-auto grid max-w-3xl gap-8 bg-td-surface px-6 py-10 print:max-w-none print:px-0 print:py-0">
        <p class="m-0 flex flex-wrap items-center justify-between gap-3 print:hidden">
            <a class="td-link" href="{{ route('teach.storefront') }}">Back to storefront</a>
            <span class="text-sm text-td-ink-2">Print this page or save it as a PDF from your browser's print menu.</span>
        </p>
        <header class="grid gap-3 border-b-4 border-td-primary pb-6">
            <div class="flex items-center gap-4">
                <x-td.avatar :name="$store['name']" :src="$store['photo_url']" size="xl" />
                <div>
                    <h1 class="m-0 font-td-display text-5xl leading-none">{{ $store['name'] }}</h1>
                    <p class="m-0 mt-2 text-xl">{{ $store['headline'] }}</p>
                </div>
            </div>
            <p class="m-0">@include('teach.partials.verify-level', ['level' => $store['verification']['level']])</p>
            <p class="m-0 text-sm text-td-ink-2">{{ $store['verification']['description'] }}</p>
        </header>

        <section class="grid gap-2">
            <h2 class="m-0 text-xl font-bold">I teach</h2>
            <ul class="m-0 grid list-disc gap-1 pl-5">
                @foreach ($store['teaching_scope'] as $sc)
                    <li>{{ $sc['subject']['name'] }}@if ($sc['level_min']), {{ $sc['curriculum']['name'] ?? '' }} {{ $sc['level_min']['name'] }}{{ $sc['level_max'] && $sc['level_max']['id'] !== $sc['level_min']['id'] ? ' to '.$sc['level_max']['name'] : '' }}@endif</li>
                @endforeach
            </ul>
            @if (! empty($store['languages']))
                <p class="m-0">In {{ collect($store['languages'])->pluck('name')->implode(', ') }}.</p>
            @endif
            <p class="m-0">{{ $store['formats']['online'] ? 'Online' : '' }}{{ $store['formats']['online'] && $store['formats']['in_person'] ? ' and ' : '' }}{{ $store['formats']['in_person'] ? 'in person around '.$store['formats']['in_person_area'] : '' }}.</p>
        </section>

        @if ($store['offerings'] !== [] || $courses->isNotEmpty())
            <section class="grid gap-2">
                <h2 class="m-0 text-xl font-bold">Lessons and courses</h2>
                <table class="w-full border-collapse">
                    <caption class="td-sr">Prices</caption>
                    <tbody>
                        @foreach ($store['offerings'] as $o)
                            <tr class="border-t border-td-line"><th scope="row" class="py-2 text-left font-normal">{{ $o['title'] }} <span class="text-td-ink-2">{{ $o['type_label'] }}, {{ $o['duration_minutes'] }} min</span></th><td class="py-2 text-right tabular-nums">{{ $o['price_minor'] === 0 ? 'Free' : $money((int) $o['price_minor'], $o['currency']) }}</td></tr>
                        @endforeach
                        @foreach ($courses as $c)
                            <tr class="border-t border-td-line"><th scope="row" class="py-2 text-left font-normal">{{ $c->title }} <span class="text-td-ink-2">online course</span></th><td class="py-2 text-right tabular-nums">{{ $c->pricing === 'free' ? 'Free' : $money((int) $c->price_minor, $c->currency) }}</td></tr>
                        @endforeach
                    </tbody>
                </table>
            </section>
        @endif

        <section class="grid gap-2 border-t-4 border-td-primary pt-6">
            <h2 class="m-0 text-xl font-bold">Book on Dot.Tutor</h2>
            <p class="m-0 break-all font-bold text-lg">{{ $link }}</p>
            <p class="m-0 text-sm text-td-ink-2">Bookings, payment and messages happen on Dot.Tutor. Learners under 18 book with a parent or guardian's approval.</p>
        </section>
    </main>
</body>
</html>
