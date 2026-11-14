{{-- /learning/library (learning.library.index): browse and share study material (D-1119). From the learning
     controller: $resources, $paginator, $filters, $subjects. LearningComposer adds the choice lists. --}}
@php
    $f = $filters;
    $young = $ctx['step'] === 'young';
    $tierIcon = ['reviewed' => 'badge-check', 'officially_sourced' => 'shield-check', 'tutor_created' => 'graduation-cap', 'community' => 'users'];
@endphp
<x-student.shell title="Study library">
    <x-td.page-head title="Study library" lede="Notes, worksheets, past papers and more, shared by learners and tutors. Every item says who shared it, its licence, its language and how it was checked." >
        <x-slot:actions><x-td.button variant="quiet" :href="route('learning.library.index', ['mine' => 1])" icon="folder">My uploads</x-td.button></x-slot:actions>
    </x-td.page-head>

    @if ($errors->any())<x-td.notice tone="danger" title="That did not go through">@foreach ($errors->all() as $m)<p style="margin:0">{{ $m }}</p>@endforeach</x-td.notice>@endif

    <div class="st-cols">
        <div class="st-stack">
            <x-td.panel title="Find material">
                <form method="GET" action="{{ route('learning.library.index') }}" role="search" class="st-form">
                    <x-td.field label="Search" for="q"><x-dot.filter-search name="q" id="q" label="Search" :value="$f['q'] ?? ''" maxlength="100" placeholder="Title, description or topic" /></x-td.field>
                    <div class="st-form__pair">
                        <x-td.field label="Subject" for="subject_id"><x-td.select name="subject_id" :value="$f['subject_id'] ?? null" placeholder="Any subject" :options="$subjectOptions" /></x-td.field>
                        <x-td.field label="Grade or level" for="level_id"><x-td.select name="level_id" :value="$f['level_id'] ?? null" placeholder="Any level" :options="$levelOptions" /></x-td.field>
                        <x-td.field label="Language" for="language"><x-td.select name="language" :value="$f['language'] ?? null" placeholder="Any language" :options="$languageOptions" /></x-td.field>
                        <x-td.field label="Type" for="type"><x-td.select name="type" :value="$f['type'] ?? null" placeholder="Any type" :options="collect(\App\Domain\Learning\Enums\ResourceType::cases())->mapWithKeys(fn ($t) => [$t->value => $t->label()])->all()" /></x-td.field>
                        <x-td.field label="How it was checked" for="tier" class="st-young-hide"><x-td.select name="tier" :value="$f['tier'] ?? null" placeholder="Any" :options="collect(\App\Domain\Learning\Enums\QualityTier::cases())->mapWithKeys(fn ($t) => [$t->value => $t->label()])->all()" /></x-td.field>
                        <x-td.field label="Price" for="free"><x-td.select name="free" :value="isset($f['free']) ? (string) (int) $f['free'] : null" placeholder="Free and paid" :options="['1' => 'Free', '0' => 'Paid']" /></x-td.field>
                    </div>
                    <div class="st-form__actions"><x-td.button type="submit" variant="primary" icon="search">Search</x-td.button>
                        @if (array_filter($f, fn ($v) => $v !== null && $v !== ''))<x-td.button variant="quiet" :href="route('learning.library.index')">Clear</x-td.button>@endif</div>
                </form>
            </x-td.panel>

            <x-td.panel :title="! empty($f['mine']) ? 'Your uploads' : $paginator->total().' '.\Illuminate\Support\Str::plural('item', $paginator->total())" flush>
                @if ($resources->isEmpty())
                    <x-td.state kind="empty" title="Nothing matches yet">Shared material appears here as soon as it is uploaded. Try fewer filters, or share something yourself.</x-td.state>
                @else
                    <ul class="st-list">
                        @foreach ($resources as $resource)
                            <li class="st-row">
                                <div class="st-stack st-stack--tight">
                                    <p class="st-row__title"><a href="{{ route('learning.library.show', $resource['id']) }}">{{ $resource['title'] }}</a></p>
                                    <p class="st-row__meta st-inline st-dot">
                                        <span>{{ $resource['type_label'] }}</span>
                                        @if ($resource['academic']['subject'] ?? null)<span>{{ $resource['academic']['subject'] }}{{ $resource['topic'] ? ', '.$resource['topic'] : '' }}</span>@endif
                                        @if ($resource['academic']['level'] ?? null)<span>{{ $resource['academic']['level'] }}</span>@endif
                                        <span lang="{{ $resource['language'] }}">{{ $languageNames[$resource['language']] ?? $resource['language'] }}</span>
                                        <span class="st-young-hide">{{ $resource['licence_label'] }}</span>
                                    </p>
                                    <div class="st-inline">
                                        <span @class(['td-badge', 'td-badge--verified' => in_array($resource['quality_tier'], ['reviewed', 'officially_sourced'], true), 'td-badge--peer' => ! in_array($resource['quality_tier'], ['reviewed', 'officially_sourced'], true)])><x-td.icon :name="$tierIcon[$resource['quality_tier']] ?? 'info'" />{{ $resource['quality_label'] }}</span>
                                        @if ($resource['status'] !== 'published')<span class="td-badge td-badge--review"><x-td.icon name="hourglass" />{{ ucfirst(str_replace('_', ' ', $resource['status'])) }}</span>@endif
                                    </div>
                                </div>
                                <div class="st-row__end"><span class="st-title">@if ($resource['is_free']) Free @else<x-td.money :minor="$resource['price_minor']" :currency="$resource['currency']" />@endif</span></div>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </x-td.panel>
            <x-td.pagination :paginator="$paginator" label="Library pages" />
        </div>

        <aside class="st-stack">
            <details class="td-panel st-disclosure" @if ($errors->any()) open @endif>
                <summary class="td-panel__head"><x-td.icon name="chevron-right" class="st-disclosure__chev" /><span class="st-disclosure__label"><h2 class="td-panel__title">Share material</h2><span class="st-disclosure__hint">Add notes, worksheets or past papers to the study library</span></span></summary>
                <form method="POST" action="{{ route('learning.library.store') }}" enctype="multipart/form-data" class="st-form td-panel__body">
                    @csrf
                    <x-td.field label="Title" for="r-title"><x-td.input name="title" id="r-title" required maxlength="160" /></x-td.field>
                    <x-td.field label="What it is" for="r-description" optional><x-td.textarea name="description" id="r-description" rows="3" maxlength="5000" /></x-td.field>
                    <div class="st-form__pair">
                        <x-td.field label="Type" for="r-type"><x-td.select name="type" id="r-type" :options="collect(\App\Domain\Learning\Enums\ResourceType::cases())->mapWithKeys(fn ($t) => [$t->value => $t->label()])->all()" /></x-td.field>
                        <x-td.field label="Written in" for="r-language" hint="The language the material itself is written in."><x-td.select name="language" id="r-language" :value="old('language', 'en')" :options="$languageOptions" :hint="true" /></x-td.field>
                        <x-td.field label="Subject" for="r-subject" optional><x-td.select name="subject_id" id="r-subject" placeholder="None" :options="$subjectOptions" /></x-td.field>
                        <x-td.field label="Grade or level" for="r-level" optional><x-td.select name="level_id" id="r-level" placeholder="Not stated" :options="$levelOptions" /></x-td.field>
                        <x-td.field label="Topic" for="r-topic" optional><x-td.input name="topic" id="r-topic" maxlength="120" /></x-td.field>
                        <x-td.field label="Difficulty" for="r-difficulty" optional><x-td.select name="difficulty" id="r-difficulty" placeholder="Not stated" :options="['introductory' => 'Introductory', 'standard' => 'Standard', 'advanced' => 'Advanced']" /></x-td.field>
                    </div>
                    <input type="hidden" name="country_code" value="ZA">
                    <x-td.field label="Who made it" for="r-ownership"><x-td.select name="ownership" id="r-ownership" :options="['own_work' => 'I made it', 'third_party' => 'Someone else made it']" /></x-td.field>
                    <x-td.field label="Licence" for="r-licence"><x-td.select name="licence" id="r-licence" :options="collect(\App\Domain\Learning\Enums\ResourceLicence::cases())->mapWithKeys(fn ($l) => [$l->value => $l->label()])->all()" /></x-td.field>
                    <x-td.field label="Source link" for="r-source" optional hint="Needed for someone else's work and for official sources. Past exam papers are accepted only as an official source with the publisher's link, and wait for a reviewer before others see them.">
                        <x-td.input type="url" name="source_url" id="r-source" maxlength="2048" :hint="true" />
                    </x-td.field>
                    @unless ($ctx['minor'])
                        <input type="hidden" name="is_free" value="1">
                        <x-td.check name="is_free" value="0" id="r-sell">Sell it (your own work only)</x-td.check>
                        <x-td.field label="Price in cents" for="r-price" optional hint="Only if you sell it. R25.00 is 2500."><x-td.input type="number" name="price_minor" id="r-price" min="0" step="1" :hint="true" /></x-td.field>
                    @else
                        <input type="hidden" name="is_free" value="1">
                    @endunless
                    <x-td.file-picker name="file" label="File" :hint="'PDF, Word, PowerPoint, Excel, OpenDocument, images, MP4 or WebM. Up to '.round(config('learning.max_upload_kb') / 1024).' MB.'" :max-kb="(int) config('learning.max_upload_kb')" />
                    <x-td.check name="rights_confirmed" value="1" required id="r-rights">I have the right to share this file under the licence I chose.</x-td.check>
                    <div class="st-form__actions"><x-td.button type="submit" variant="primary" icon="upload">Upload</x-td.button></div>
                </form>
            </details>
        </aside>
    </div>
</x-student.shell>
