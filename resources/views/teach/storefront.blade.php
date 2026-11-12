@php
    $money = fn (int $minor, ?string $c = null) => \App\Domain\Teach\Support\TutorContext::money($minor, $c);
    $reviews = $store['reviews'];
@endphp
<x-layouts::workspace title="Storefront">
    <x-td.page-head title="Storefront" lede="Your page on Dot.Tutor: what learners read before they book or buy, and the links you share.">
        <x-slot:actions>
            <x-td.button href="{{ $liveUrl }}" icon="external-link">Open the live page</x-td.button>
            <x-td.button href="{{ route('teach.storefront.promo') }}" icon="file">Promotional page</x-td.button>
        </x-slot:actions>
    </x-td.page-head>

    @unless ($profile->isApproved())
        <x-td.notice tone="info" title="Only you can see this until you are approved" class="mb-6">Learners cannot open your storefront or book you before an operator approves your profile. <a class="td-link" href="{{ route('teach.profile') }}">Profile and verification</a></x-td.notice>
    @endunless

    <x-td.tutor-standing :profile="$profile" class="mb-6 md:grid-cols-3" />

    <div class="td-panels td-panels--2">
        <x-td.panel title="Preview">
            <article class="grid min-w-0 grid-cols-[minmax(0,1fr)] gap-5 [overflow-wrap:anywhere]" aria-label="Storefront preview">
                <header class="flex flex-wrap items-start gap-4">
                    <x-td.avatar :name="$store['name']" :src="$store['photo_url']" size="lg" />
                    <div class="grid min-w-0 gap-2">
                        <h3 class="m-0 font-td-display text-2xl">{{ $store['name'] }}</h3>
                        <p class="m-0">{{ $store['headline'] ?: 'No headline yet' }}</p>
                        <div>@include('student.partials.verification', ['verification' => $store['verification']])</div>
                    </div>
                </header>
                @if ($store['introduction'])<p class="m-0 whitespace-pre-line">{{ $store['introduction'] }}</p>@endif

                <section aria-label="Teaches">
                    <h4 class="mb-2 mt-0 text-sm font-bold text-td-ink-2">Teaches</h4>
                    @if ($store['teaching_scope'] === [])
                        <p class="m-0 text-td-ink-2">No subjects yet.</p>
                    @else
                        <ul class="m-0 grid list-none gap-2 p-0">
                            @foreach ($store['teaching_scope'] as $sc)
                                @php $stage = $sc['level_min'] ? \App\Domain\Teach\Services\AcademicOptions::stageKey($levelIndex[$sc['level_min']['id']]['stage'] ?? null) : null; @endphp
                                <li class="flex flex-wrap items-center gap-2">
                                    <x-td.pill kind="subject">{{ $sc['subject']['name'] }}</x-td.pill>
                                    @if ($sc['level_min'])
                                        <x-td.pill kind="level" :stage="$stage">{{ $levelIndex[$sc['level_min']['id']]['curriculum'] ?? '' }} {{ $sc['level_min']['name'] }}{{ $sc['level_max'] && $sc['level_max']['id'] !== $sc['level_min']['id'] ? ' to '.$sc['level_max']['name'] : '' }}</x-td.pill>
                                    @else
                                        <span class="text-sm text-td-ink-2">any level</span>
                                    @endif
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </section>

                <section aria-label="Teaching languages">
                    <h4 class="mb-2 mt-0 text-sm font-bold text-td-ink-2">Teaches in</h4>
                    @if (empty($store['languages']))
                        <p class="m-0 text-td-ink-2">No teaching languages declared.</p>
                    @else
                        <div class="flex flex-wrap gap-2">
                            @foreach ($store['languages'] as $l)
                                <x-td.language-chip :code="$l['code']" :name="$l['name']" :verified="(bool) $l['verified']" />
                            @endforeach
                        </div>
                    @endif
                </section>

                <section aria-label="Offerings">
                    <h4 class="mb-2 mt-0 text-sm font-bold text-td-ink-2">Book</h4>
                    @forelse ($store['offerings'] as $o)
                        <p class="m-0 flex flex-wrap justify-between gap-2 border-t border-td-line py-2 first:border-t-0">
                            <span>{{ $o['title'] }} <span class="text-td-ink-2">{{ $o['duration_minutes'] ?? '' }} min</span></span>
                            <span class="tabular-nums">{{ $o['type_label'] }}, {{ $money((int) $o['price_minor'], $o['currency']) }}</span>
                        </p>
                    @empty
                        <p class="m-0 text-td-ink-2">Nothing bookable yet. <a class="td-link" href="{{ route('teach.offerings.create') }}">Create an offering</a></p>
                    @endforelse
                </section>

                <section aria-label="Courses and materials">
                    <h4 class="mb-2 mt-0 text-sm font-bold text-td-ink-2">Courses and study materials</h4>
                    @if ($courses->isEmpty() && $materials->isEmpty())
                        <p class="m-0 text-td-ink-2">None published yet.</p>
                    @else
                        <ul class="m-0 grid list-none gap-1 p-0">
                            @foreach ($courses as $c)<li>{{ $c->title }} <span class="text-td-ink-2">course,</span> {{ $c->pricing === 'free' ? 'free' : $money((int) $c->price_minor, $c->currency) }}</li>@endforeach
                            @foreach ($materials as $m)<li>{{ $m->title }} <span class="text-td-ink-2">study material,</span> {{ $m->is_free ? 'free' : $money((int) $m->price_minor, $m->currency) }}</li>@endforeach
                        </ul>
                        <p class="mb-0 mt-2 text-sm text-td-ink-2">The live storefront lists offerings; courses and materials are found through the course catalogue and library.</p>
                    @endif
                </section>

                <section aria-label="Reviews">
                    <h4 class="mb-2 mt-0 text-sm font-bold text-td-ink-2">Reviews</h4>
                    <p class="m-0">{{ $reviews['count'] === 0 ? 'No reviews yet. Learners can review a lesson after it is completed.' : $reviews['count'].' published '.\Illuminate\Support\Str::plural('review', $reviews['count']).', average '.number_format($reviews['average'], 1).' of 5.' }}</p>
                </section>
            </article>
        </x-td.panel>

        <div class="td-panels content-start border-0">
            <x-td.panel title="Share your storefront">
                <div class="grid gap-4">
                    <x-td.field label="Storefront link" for="share-url" hint="Anyone can open it without an account; booking and messages need sign-in. Contact details in your text are hidden from visitors.">
                        <x-td.input name="share_url" id="share-url" :value="$shareUrl" readonly :hint="true" data-teach-copy-source />
                    </x-td.field>
                    <x-td.button size="sm" data-teach-copy="share-url" hidden>Copy link</x-td.button>
                    <x-td.field label="Referral link" for="referral-url" hint="Counts signed-in visits it brings, so Insights can show which bookings followed. It gives no credit or discount: Dot.Tutor has no referral reward.">
                        <x-td.input name="referral_url" id="referral-url" :value="$referralUrl" readonly :hint="true" />
                    </x-td.field>
                    <x-td.button size="sm" data-teach-copy="referral-url" hidden>Copy referral link</x-td.button>
                    <p class="m-0 text-sm">Visits through your referral link: <strong class="tabular-nums">{{ $visits }}</strong> in total, <strong class="tabular-nums">{{ $visits30 }}</strong> in the last 30 days.</p>
                </div>
            </x-td.panel>
            <x-td.panel title="Edit what learners read">
                <form method="POST" action="{{ route('teach.storefront.update') }}" class="grid gap-4">
                    @csrf @method('PUT')
                    <x-td.field label="Headline" for="headline"><x-td.input name="headline" :value="$profile->headline" maxlength="160" required /></x-td.field>
                    <x-td.field label="Short link name" for="public_handle" optional hint="Makes your link /u/your-name. Lower-case letters, digits and dashes.">
                        <x-td.input name="public_handle" :value="$profile->public_handle" maxlength="60" :hint="true" />
                    </x-td.field>
                    <x-td.field label="Introduction" for="introduction"><x-td.textarea name="introduction" :value="$profile->bio" rows="6" required /></x-td.field>
                    <x-td.field label="How you teach" for="teaching_approach" optional><x-td.textarea name="teaching_approach" :value="$profile->teaching_approach" rows="4" /></x-td.field>
                    <div><x-td.button type="submit" variant="primary">Save storefront</x-td.button></div>
                    <p class="m-0 text-sm text-td-ink-2">Subjects, languages, qualifications and availability are edited in <a class="td-link" href="{{ route('teach.profile') }}">Profile and verification</a>; prices in <a class="td-link" href="{{ route('teach.offerings') }}">Offerings</a>.</p>
                </form>
            </x-td.panel>
        </div>
    </div>
</x-layouts::workspace>
