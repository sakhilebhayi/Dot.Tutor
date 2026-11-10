<x-app-layout>
    <x-slot name="header">Resource library</x-slot>
    @include('learning.partials.styles')
    <div class="ln">
        <h1>Resource library</h1>
        <p class="muted">Study material shared by learners and tutors. Every item shows who shared it, its licence and how it was checked. "Reviewed" and "Officially sourced" appear only after a named reviewer has recorded a review.</p>
        @include('learning.partials.flash')

        <div class="grid two">
            <section aria-labelledby="find">
                <h2 id="find">Find material</h2>
                <form method="GET" action="{{ route('learning.library.index') }}" role="search">
                    <label for="q">Search</label>
                    <input id="q" name="q" type="search" value="{{ $filters['q'] ?? '' }}" placeholder="Title, description or topic">
                    <div class="row">
                        <div style="flex:1;min-width:10rem">
                            <label for="f-type">Type</label>
                            <select id="f-type" name="type"><option value="">Any type</option>
                                @foreach (\App\Domain\Learning\Enums\ResourceType::cases() as $type)<option value="{{ $type->value }}" @selected(($filters['type'] ?? '') === $type->value)>{{ $type->label() }}</option>@endforeach
                            </select>
                        </div>
                        <div style="flex:1;min-width:10rem">
                            <label for="f-subject">Subject</label>
                            <select id="f-subject" name="subject_id"><option value="">Any subject</option>
                                @foreach ($subjects as $subject)<option value="{{ $subject->id }}" @selected(($filters['subject_id'] ?? null) == $subject->id)>{{ $subject->name }}</option>@endforeach
                            </select>
                        </div>
                        <div style="flex:1;min-width:10rem">
                            <label for="f-tier">Quality</label>
                            <select id="f-tier" name="tier"><option value="">Any</option>
                                @foreach (\App\Domain\Learning\Enums\QualityTier::cases() as $tier)<option value="{{ $tier->value }}" @selected(($filters['tier'] ?? '') === $tier->value)>{{ $tier->label() }}</option>@endforeach
                            </select>
                        </div>
                        <div style="flex:1;min-width:8rem">
                            <label for="f-language">Language code</label>
                            <input id="f-language" name="language" type="text" value="{{ $filters['language'] ?? '' }}" maxlength="20">
                        </div>
                    </div>
                    <p class="row" style="margin-top:1rem"><button class="btn ghost" type="submit">Search</button> <a href="{{ route('learning.library.index', ['mine' => 1]) }}">My uploads</a></p>
                </form>

                @if ($resources->isEmpty())
                    <p class="muted">Nothing matches yet. Shared material appears here as soon as it is uploaded.</p>
                @else
                    <ul class="plain">
                        @foreach ($resources as $resource)
                            <li>
                                <a href="{{ route('learning.library.show', $resource['id']) }}"><strong>{{ $resource['title'] }}</strong></a>
                                <div class="row muted">
                                    <span>{{ $resource['type_label'] }}</span>
                                    <span>{{ $resource['academic']['subject'] ?? '' }}{{ $resource['topic'] ? ' · '.$resource['topic'] : '' }}</span>
                                    <span class="tag {{ in_array($resource['quality_tier'], ['reviewed', 'officially_sourced'], true) ? 'ok' : '' }}">{{ $resource['quality_label'] }}</span>
                                    <span>{{ $resource['licence_label'] }}</span>
                                    <span>{{ $resource['is_free'] ? 'Free' : '' }}@unless ($resource['is_free'])@include('learning.partials.money', ['minor' => $resource['price_minor'], 'currency' => $resource['currency']])@endunless</span>
                                    @if ($resource['status'] !== 'published') <span class="tag warn">{{ str_replace('_', ' ', $resource['status']) }}</span> @endif
                                </div>
                            </li>
                        @endforeach
                    </ul>
                    {{ $paginator->links() }}
                @endif
            </section>

            <section class="panel" aria-labelledby="share">
                <h2 id="share" style="margin-top:0">Share material</h2>
                <form method="POST" action="{{ route('learning.library.store') }}" enctype="multipart/form-data">
                    @csrf
                    <label for="r-title">Title</label>
                    <input id="r-title" name="title" type="text" required maxlength="160" value="{{ old('title') }}">
                    <label for="r-description">Description</label>
                    <textarea id="r-description" name="description" maxlength="5000">{{ old('description') }}</textarea>
                    <label for="r-type">Type</label>
                    <select id="r-type" name="type">
                        @foreach (\App\Domain\Learning\Enums\ResourceType::cases() as $type)<option value="{{ $type->value }}" @selected(old('type') === $type->value)>{{ $type->label() }}</option>@endforeach
                    </select>
                    <label for="r-subject">Subject</label>
                    <select id="r-subject" name="subject_id"><option value="">None</option>
                        @foreach ($subjects as $subject)<option value="{{ $subject->id }}" @selected(old('subject_id') == $subject->id)>{{ $subject->name }}</option>@endforeach
                    </select>
                    <label for="r-topic">Topic</label>
                    <input id="r-topic" name="topic" type="text" maxlength="120" value="{{ old('topic') }}">
                    <div class="row">
                        <div style="flex:1;min-width:7rem"><label for="r-country">Country code</label><input id="r-country" name="country_code" type="text" maxlength="2" value="{{ old('country_code', 'ZA') }}"></div>
                        <div style="flex:1;min-width:7rem"><label for="r-language">Language code</label><input id="r-language" name="language" type="text" maxlength="20" value="{{ old('language', 'en') }}"></div>
                    </div>
                    <label for="r-difficulty">Difficulty</label>
                    <select id="r-difficulty" name="difficulty"><option value="">Not stated</option><option value="introductory">Introductory</option><option value="standard">Standard</option><option value="advanced">Advanced</option></select>
                    <label for="r-institution">Institution (if it belongs to one)</label>
                    <input id="r-institution" name="institution" type="text" maxlength="160" value="{{ old('institution') }}">
                    <label for="r-ownership">Who made it</label>
                    <select id="r-ownership" name="ownership"><option value="own_work">I made it</option><option value="third_party" @selected(old('ownership') === 'third_party')>Someone else made it</option></select>
                    <label for="r-licence">Licence</label>
                    <select id="r-licence" name="licence">
                        @foreach (\App\Domain\Learning\Enums\ResourceLicence::cases() as $licence)<option value="{{ $licence->value }}" @selected(old('licence') === $licence->value)>{{ $licence->label() }}</option>@endforeach
                    </select>
                    <label for="r-source">Source link (https)</label>
                    <input id="r-source" name="source_url" type="url" maxlength="2048" value="{{ old('source_url') }}" aria-describedby="source-hint">
                    <p class="hint" id="source-hint">Required for someone else's work and for official sources. Past exam papers are accepted only as "Official source" with the publisher's link, such as the Department of Basic Education's NSC past papers page, and wait for a reviewer before others can see them.</p>
                    <input type="hidden" name="is_free" value="1">
                    <label><input type="checkbox" name="is_free" value="0" @checked(old('is_free') === '0')> Sell it (your own work only)</label>
                    <label for="r-price">Price in cents</label>
                    <input id="r-price" name="price_minor" type="number" min="0" value="{{ old('price_minor') }}">
                    <label for="r-file">File</label>
                    <input id="r-file" name="file" type="file" required>
                    <p class="hint">PDF, Word, PowerPoint, Excel, OpenDocument, images, MP4 or WebM. Up to {{ round(config('learning.max_upload_kb') / 1024) }} MB.</p>
                    <label><input type="checkbox" name="rights_confirmed" value="1" required> I have the right to share this file under the licence I chose.</label>
                    <p style="margin-top:1rem"><button class="btn" type="submit">Upload</button></p>
                </form>
            </section>
        </div>
    </div>
</x-app-layout>
