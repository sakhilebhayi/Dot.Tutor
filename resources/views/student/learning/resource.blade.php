{{-- /learning/library/{resource} (learning.library.show) (D-1119): original language, licence, how it was checked,
     download or buy, recommend, translation side by side, and reports. From the learning controller: $resource, $data. --}}
@php
    $checked = in_array($data['quality_tier'], ['reviewed', 'officially_sourced'], true);
    $language = $languageNames[$data['language']] ?? $data['language'];
@endphp
<x-student.shell :title="$data['title']">
    <x-td.page-head :title="$data['title']" :lede="$data['type_label'].' · shared by '.$data['contributor']">
        <x-slot:actions><x-td.button variant="quiet" icon="arrow-left" :href="route('learning.library.index')">Study library</x-td.button></x-slot:actions>
    </x-td.page-head>

    @if ($errors->any())<x-td.notice tone="danger" title="That did not go through">@foreach ($errors->all() as $m)<p style="margin:0">{{ $m }}</p>@endforeach</x-td.notice>@endif
    @if ($data['status'] !== 'published')
        <x-td.notice tone="warning" title="{{ ucfirst(str_replace('_', ' ', $data['status'])) }}">Only you and reviewers can see this until it is published.</x-td.notice>
    @endif

    <div class="st-cols">
        <div class="st-stack">
            <x-td.panel title="About it">
                <div class="st-stack">
                    <div class="st-inline">
                        <span @class(['td-badge', 'td-badge--verified' => $checked, 'td-badge--peer' => ! $checked])><x-td.icon :name="$checked ? 'badge-check' : 'users'" />{{ $data['quality_label'] }}</span>
                        @if ($data['reviewed_by'])<span class="st-small">Reviewed by {{ $data['reviewed_by']['name'] }} on {{ $data['reviewed_by']['at'] }}</span>@endif
                    </div>
                    <p class="st-original" style="margin:0"><x-td.icon name="languages" /><span>Original language: <strong lang="{{ $data['language'] }}">{{ $language }}</strong></span></p>
                    @if ($data['description'])<p class="st-prose" style="margin:0;white-space:pre-line" lang="{{ $data['language'] }}">{{ $data['description'] }}</p>@endif
                    <dl class="st-facts">
                        <dt>Subject</dt><dd>{{ $data['academic']['subject'] ?? 'Not stated' }}</dd>
                        @if ($data['academic']['level'] ?? null)<dt>Level</dt><dd>{{ $data['academic']['level'] }}</dd>@endif
                        <dt>Topic</dt><dd>{{ $data['topic'] ?? 'Not stated' }}</dd>
                        <dt>Difficulty</dt><dd>{{ $data['difficulty'] ? ucfirst($data['difficulty']) : 'Not stated' }}</dd>
                        @if ($data['institution'])<dt>Institution</dt><dd>{{ $data['institution'] }}</dd>@endif
                        <dt>Licence</dt><dd>{{ $data['licence_label'] }}{{ $data['ownership'] === 'third_party' ? ', shared from another author' : ', the contributor\'s own work' }}</dd>
                        @if ($data['source_url'])<dt>Source</dt><dd><a class="td-link" href="{{ $data['source_url'] }}" rel="noopener noreferrer" target="_blank">{{ $data['source_url'] }}</a></dd>@endif
                        <dt>File</dt><dd>{{ \App\Domain\Student\Support\FileKind::label($data['mime']) }}, {{ number_format($data['size'] / 1024) }} KB</dd>
                        <dt>Recommended by</dt><dd>{{ $data['recommendations'] }} {{ \Illuminate\Support\Str::plural('person', $data['recommendations']) }}</dd>
                    </dl>
                </div>
            </x-td.panel>

            @if (Route::has('ai.translations.show') && ($data['description'] || $data['title']))
                <x-td.panel title="In another language">
                    <p style="margin:0">See a translation of the {{ $data['description'] ? 'description' : 'title' }} next to the original. The file itself stays in {{ $language }}. Machine translations are labelled until someone who reads the language checks them, and formulas are compared with the original.</p>
                    <div class="st-inline" style="margin-top:var(--td-space-3)">
                        <x-td.button size="sm" icon="languages" :href="route('ai.translations.show', ['learning.resource', $resource->id, $data['description'] ? 'description' : 'title'])">Original and translation</x-td.button>
                    </div>
                    @unless ($aiConfigured)<p class="st-small" style="margin:var(--td-space-2) 0 0">Machine translation is not switched on yet, so only translations people have added are shown.</p>@endunless
                </x-td.panel>
            @endif

            @if ($data['reviews'])
                <x-td.panel title="Review record" flush>
                    <ul class="st-list">@foreach ($data['reviews'] as $review)<li class="st-row"><div><p class="st-row__title">{{ $review['reviewer'] }}: {{ str_replace('_', ' ', $review['outcome']) }}</p><p class="st-row__meta">{{ $review['notes'] }} · {{ \Illuminate\Support\Carbon::parse($review['at'])->toDateString() }}</p></div></li>@endforeach</ul>
                </x-td.panel>
            @endif

            @if ($data['reports'])
                <x-td.panel title="Reports on this material" flush>
                    <ul class="st-list">
                        @foreach ($data['reports'] as $report)
                            <li class="st-row" style="grid-template-columns:minmax(0,1fr)">
                                <div class="st-stack st-stack--tight">
                                    <p class="st-row__title">{{ ucfirst($report['kind']) }}, {{ $report['status'] }}</p>
                                    <p class="st-row__meta">{{ $report['details'] }}</p>
                                    @if ($data['is_owner'] && $report['kind'] === 'copyright' && in_array($report['status'], ['open', 'upheld'], true))
                                        <form method="POST" action="{{ route('learning.library.reports.counter', $report['id']) }}" class="st-form">@csrf
                                            <x-td.field label="Your counter-notice" for="counter-{{ $report['id'] }}"><x-td.textarea name="statement" id="counter-{{ $report['id'] }}" minlength="20" required rows="3" /></x-td.field>
                                            <div><x-td.button type="submit" size="sm">Send counter-notice</x-td.button></div>
                                        </form>
                                    @endif
                                </div>
                            </li>
                        @endforeach
                    </ul>
                </x-td.panel>
            @endif
        </div>

        <aside class="st-stack">
            <x-td.panel title="{{ $data['can_download'] ? 'Get it' : ($data['is_free'] ? 'Get it' : 'Buy it') }}">
                <div class="st-stack">
                    @if ($data['can_download'])
                        <x-td.button variant="primary" size="lg" icon="download" :href="route('learning.library.download', $resource)">Download</x-td.button>
                        <p class="st-small" style="margin:0">{{ number_format($data['size'] / 1024) }} KB.</p>
                    @elseif (! $data['is_free'])
                        <div class="st-total"><span>Price</span><x-td.money :minor="$data['price_minor']" :currency="$data['currency']" /></div>
                        @unless ($data['is_owner'])
                            <form method="POST" action="{{ route('learning.library.checkout', $resource) }}">@csrf
                                <input type="hidden" name="idempotency_key" value="{{ \Illuminate\Support\Str::uuid() }}">
                                <x-td.button type="submit" variant="primary">Buy and download</x-td.button>
                            </form>
                            @if ($ctx['minor'])<p class="st-small" style="margin:0">Your parent or guardian approves the purchase before you pay.</p>@endif
                        @endunless
                    @endif
                    @unless ($data['is_owner'])
                        <form method="POST" action="{{ route('learning.library.recommend', $resource) }}">@csrf
                            @if ($data['recommended_by_you']) @method('DELETE') <x-td.button type="submit" size="sm" variant="quiet" icon="star">Remove my recommendation</x-td.button>
                            @else <x-td.button type="submit" size="sm" icon="star">Recommend it to others</x-td.button> @endif
                        </form>
                    @else
                        <form method="POST" action="{{ route('learning.library.withdraw', $resource) }}">@csrf<x-td.button type="submit" size="sm" variant="danger">Withdraw it</x-td.button></form>
                    @endunless
                </div>
            </x-td.panel>

            <details class="td-panel st-disclosure">
                <summary class="td-panel__head"><x-td.icon name="chevron-right" class="st-disclosure__chev" /><span class="st-disclosure__label"><h2 class="td-panel__title">Report or suggest a change</h2><span class="st-disclosure__hint">A mistake, a better version, or something that should not be here</span></span></summary>
                <form method="POST" action="{{ route('learning.library.reports.store', $resource) }}" class="st-form td-panel__body">@csrf
                    <x-td.field label="What is it about?" for="kind">
                        <x-td.select name="kind" :options="array_filter(['incorrect' => 'Something is wrong', 'improvement' => 'Suggest an improvement', 'copyright' => $data['is_owner'] ? null : 'Copyright: this is my work, shared without permission', 'other' => 'Something else'])" />
                    </x-td.field>
                    <x-td.field label="Details" for="details"><x-td.textarea name="details" minlength="10" required rows="3" /></x-td.field>
                    <p class="st-small" style="margin:0">A copyright notice also needs the rights holder's name, a link to the original work and the good-faith statement. A complete notice hides the file until a moderator decides.</p>
                    <x-td.field label="Rights holder's name" for="claimant" optional><x-td.input name="claimant_name" id="claimant" maxlength="160" /></x-td.field>
                    <x-td.field label="Link to the original work" for="work" optional><x-td.input name="work_reference" id="work" maxlength="2048" /></x-td.field>
                    <x-td.check name="good_faith" value="1" id="good-faith">I state in good faith that this use is not authorised by the rights holder (copyright only).</x-td.check>
                    <div><x-td.button type="submit" size="sm">Send report</x-td.button></div>
                </form>
            </details>
        </aside>
    </div>
</x-student.shell>
