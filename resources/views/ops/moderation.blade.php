@php
    use App\Domain\Ops\Http\Controllers\ContentController;
    use App\Domain\Learning\Enums\QualityTier;
    $tz = config('tutor.timezone');
    $tabs = collect(ContentController::TABS)->map(fn ($label, $key) => ['label' => $label, 'href' => route('ops.moderation', ['tab' => $key]), 'current' => $tab === $key, 'count' => $counts[$key] ?? null])->values()->all();
    $names = fn ($u) => $u ? \App\Domain\Community\Support\Names::short($u) : 'Deleted account';
@endphp
<x-layouts::operations title="Content moderation">
    <x-td.page-head title="Content moderation" lede="Held room posts, library resources waiting for a source check or a quality review, and complaints. Reports about people are worked in Safeguarding." />
    <x-td.tabs :items="$tabs" label="Content queues" class="mb-5" />

    @if ($tab === 'posts')
        <x-td.panel flush>
            @if ($posts->isEmpty())
                <x-td.state kind="success" title="No held posts">Posts by learners under 13 and posts held after reports wait here before anyone else sees them.</x-td.state>
            @else
                <ul class="ops-work">
                    @foreach ($posts as $post)
                        @php($original = $post->original_text ? json_decode($post->original_text, true) : null)
                        <li>
                            <div class="grid gap-2" style="min-width:0">
                                <p class="ops-work__meta">{{ $post->room?->displayName() }} · {{ \App\Domain\Community\Models\Post::kindLabel($post->kind) }} by {{ $names($post->author) }} ·
                                    @include('ops.partials.tag', match ($post->held_reason) {
                                        'pre_moderation' => ['text' => 'Learner under 13', 'tone' => 'info', 'icon' => 'hand-heart'],
                                        'reported_child_safety' => ['text' => 'Child-safety report', 'tone' => 'danger'],
                                        default => ['text' => $post->reporters_count.' reports', 'tone' => 'warning'],
                                    }) · held {{ $post->updated_at?->diffForHumans() }}</p>
                                @if ($post->title)<p class="ops-work__title">{{ $post->title }}</p>@endif
                                <div class="whitespace-pre-line" style="max-width: var(--td-measure)">{{ $post->body }}</div>
                                @if ($original)
                                    <details class="text-sm"><summary class="cursor-pointer">Original text, before contact details were hidden</summary><div class="mt-2 whitespace-pre-line">{{ $original['body'] ?? '' }}</div></details>
                                @endif
                                <form method="POST" action="{{ route('ops.moderation.posts.decide', $post) }}" class="ops-act">
                                    @csrf
                                    <x-td.field label="Note" for="post-note-{{ $post->id }}" optional><x-td.input name="note" id="post-note-{{ $post->id }}" /></x-td.field>
                                    <x-td.button type="submit" name="decision" value="release" variant="primary" size="sm">Release</x-td.button>
                                    <x-td.button type="submit" name="decision" value="remove" variant="danger" size="sm">Remove</x-td.button>
                                </form>
                            </div>
                        </li>
                    @endforeach
                </ul>
                @if ($posts->hasPages())<div class="px-5 py-3"><x-td.pagination :paginator="$posts" label="Held post pages" /></div>@endif
            @endif
        </x-td.panel>
    @elseif ($tab === 'resources')
        <x-td.panel flush>
            @if ($resources->isEmpty())
                <x-td.state kind="success" title="Nothing waiting">Past papers held for a source check, and published community and tutor resources that have not been reviewed, appear here.</x-td.state>
            @else
                <ul class="ops-work">
                    @foreach ($resources as $r)
                        <li>
                            <div class="grid gap-2">
                                <p class="ops-work__title"><a href="{{ route('learning.library.show', $r) }}">{{ $r->title }}</a>
                                    <span class="ops-muted" style="font-weight:400">· {{ $r->type?->label() ?? $r->type }} · {{ $r->subject?->name }} · {{ $r->licence?->label() }} · by {{ $names($r->owner) }}</span></p>
                                <p class="ops-work__meta">
                                    @include('ops.partials.tag', $r->status === \App\Domain\Learning\Enums\ResourceStatus::PendingReview ? ['text' => 'Held until the source is confirmed', 'tone' => 'warning'] : ['text' => $r->quality_tier->label(), 'icon' => 'circle-dashed'])
                                    @if ($r->source_url) · source: <a href="{{ $r->source_url }}" rel="noopener noreferrer nofollow" target="_blank">{{ parse_url($r->source_url, PHP_URL_HOST) }}</a>@endif
                                    @if ($r->reviews->isNotEmpty()) · last review: {{ $r->reviews->first()->outcome }} by {{ $r->reviews->first()->reviewer_name }}@endif
                                </p>
                                <form method="POST" action="{{ route('ops.moderation.resources.review', $r) }}" class="ops-act">
                                    @csrf
                                    <x-td.field label="Notes (needed to ask for changes or reject)" for="res-notes-{{ $r->id }}" optional><x-td.input name="notes" id="res-notes-{{ $r->id }}" /></x-td.field>
                                    @if ($r->licence?->value === 'official_source')
                                        <x-td.button type="submit" name="outcome" value="source_confirmed" variant="primary" size="sm">Source confirmed</x-td.button>
                                    @endif
                                    <x-td.button type="submit" name="outcome" value="approved" size="sm">Reviewed, accurate</x-td.button>
                                    <x-td.button type="submit" name="outcome" value="changes_requested" size="sm" variant="quiet">Ask for changes</x-td.button>
                                    <x-td.button type="submit" name="outcome" value="rejected" size="sm" variant="danger">Reject</x-td.button>
                                </form>
                            </div>
                        </li>
                    @endforeach
                </ul>
                @if ($resources->hasPages())<div class="px-5 py-3"><x-td.pagination :paginator="$resources" label="Resource pages" /></div>@endif
            @endif
        </x-td.panel>
        <p class="mt-3 text-sm ops-muted">"Reviewed" and "Officially sourced" appear on a resource only from a recorded review with the reviewer's name. You cannot review your own material.</p>
    @elseif ($tab === 'complaints')
        <x-td.panel flush>
            @if ($complaints->isEmpty())
                <x-td.state kind="success" title="No complaints">Copyright notices, reports of incorrect material and suggestions appear here.</x-td.state>
            @else
                <ul class="ops-work">
                    @foreach ($complaints as $c)
                        <li>
                            <div class="grid gap-2">
                                <p class="ops-work__title">{{ ucfirst($c->kind) }} · <a href="{{ route('learning.library.show', $c->resource_id) }}">{{ $c->resource?->title ?? 'Resource '.$c->resource_id }}</a></p>
                                <p class="ops-work__meta">
                                    @include('ops.partials.tag', match ($c->status) {
                                        'open' => ['text' => $c->kind === 'copyright' && $c->resource?->status?->value === 'taken_down' ? 'Open, file taken down' : 'Open', 'tone' => 'warning'],
                                        'countered' => ['text' => 'Owner sent a counter-notice', 'tone' => 'info'],
                                        'upheld' => ['text' => 'Upheld', 'tone' => 'danger'],
                                        default => ['text' => ucfirst($c->status)],
                                    }) · {{ $c->created_at->timezone($tz)->format('j M Y') }}
                                    @if ($c->claimant_name) · rights holder: {{ $c->claimant_name }}@endif
                                    @if ($c->work_reference) · work: {{ $c->work_reference }}@endif
                                </p>
                                @if ($c->details)<p class="m-0" style="max-width: var(--td-measure)">{{ $c->details }}</p>@endif
                                @if ($c->counter_notice)<p class="m-0 text-sm" style="max-width: var(--td-measure)"><strong>Counter-notice:</strong> {{ $c->counter_notice }}</p>@endif
                                @if (in_array($c->status, ['open', 'countered'], true))
                                    <form method="POST" action="{{ route('ops.moderation.complaints.decide', $c) }}" class="ops-act">
                                        @csrf
                                        <x-td.field label="Notes" for="c-notes-{{ $c->id }}" optional><x-td.input name="notes" id="c-notes-{{ $c->id }}" /></x-td.field>
                                        <x-td.button type="submit" name="decision" value="uphold" variant="danger" size="sm">Uphold (keep down)</x-td.button>
                                        <x-td.button type="submit" name="decision" value="dismiss" size="sm">Dismiss (restore)</x-td.button>
                                    </form>
                                @elseif ($c->decision_notes)
                                    <p class="m-0 text-sm ops-muted">Decision: {{ $c->decision_notes }}</p>
                                @endif
                            </div>
                        </li>
                    @endforeach
                </ul>
                @if ($complaints->hasPages())<div class="px-5 py-3"><x-td.pagination :paginator="$complaints" label="Complaint pages" /></div>@endif
            @endif
        </x-td.panel>
    @elseif ($tab === 'library')
        <div class="td-panels td-panels--2">
            <x-td.panel title="Published resources by quality tier">
                @include('ops.partials.bars', ['rows' => collect(QualityTier::cases())->map(fn ($t) => ['label' => $t->label(), 'value' => (int) ($tiers[$t->value] ?? 0)])->all(), 'label' => 'Resources by tier'])
            </x-td.panel>
            <x-td.panel title="All resources by state">
                @include('ops.partials.bars', ['rows' => collect(\App\Domain\Learning\Enums\ResourceStatus::cases())->map(fn ($s) => ['label' => ucfirst(str_replace('_', ' ', $s->value)), 'value' => (int) ($statuses[$s->value] ?? 0)])->all(), 'label' => 'Resources by state'])
            </x-td.panel>
        </div>
    @else
        <x-td.notice tone="info" class="mb-5">Courses go live when an approved tutor passes the publish checks; there is no review before publication, and learners cannot yet report a course (a learning-domain item). Listed here so you can open any course.</x-td.notice>
        <x-td.panel flush>
            @if ($courses->isEmpty())
                <x-td.state kind="empty" title="No published courses yet" />
            @else
                <x-td.table caption="Published courses, newest first" caption-hidden dense>
                    <thead><tr><th scope="col">Course</th><th scope="col">Creator</th><th scope="col">Price</th><th scope="col">Published</th></tr></thead>
                    <tbody>
                        @foreach ($courses as $c)
                            <tr>
                                <td><a href="{{ route('learning.courses.show', $c->id) }}">{{ $c->title }}</a></td>
                                <td>{{ $c->owner }}</td>
                                <td class="ops-num">{{ $c->pricing === 'free' || ! $c->price_minor ? 'Free' : \App\Domain\Money\Money::of((int) $c->price_minor, $c->currency ?: 'ZAR')->format() }}</td>
                                <td class="ops-num text-sm">{{ $c->published_at ? \Illuminate\Support\Carbon::parse($c->published_at)->timezone($tz)->format('j M Y') : '—' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </x-td.table>
                @if ($courses->hasPages())<div class="px-5 py-3"><x-td.pagination :paginator="$courses" label="Course pages" /></div>@endif
            @endif
        </x-td.panel>
    @endif
</x-layouts::operations>
