{{-- The body of the full search page, the same in every shell. Expects $q, $scope, $scopes, $typed, $words, $groups, $pages. --}}
<x-td.page-head title="Search" :lede="$typed ? 'Results for “'.$q.'” in '.($scopes[$scope] ?? 'Learning').'.' : 'Type at least '.\App\Support\Search\TutorSearch::MIN_LENGTH.' characters in the search box at the top of the page.'" />

@if (count($scopes) > 1)
    <nav class="mb-5 flex flex-wrap items-center gap-2" aria-label="Search in">
        <span class="text-sm text-td-ink-2">Search in</span>
        @foreach ($scopes as $key => $label)
            <a class="td-chip" href="{{ route('search', array_filter(['q' => $q, 'scope' => $key])) }}" @if ($key === $scope) aria-current="true" @endif>{{ $label }}</a>
        @endforeach
    </nav>
@endif

@if (! $typed)
    <x-td.panel title="Go to">
        <ul class="hm-rows">
            @foreach ($pages as $hit)
                <li class="hm-row"><div><a class="hm-row__link" href="{{ $hit->url }}">{{ $hit->title }}</a></div></li>
            @endforeach
        </ul>
    </x-td.panel>
@elseif ($groups === [])
    <x-td.state kind="empty" title="Nothing matches “{{ $q }}”">Try fewer words, a name or a subject. This search only looks at what you can open in {{ $scopes[$scope] ?? 'Learning' }}.</x-td.state>
@else
    <div class="td-panels">
        @foreach ($groups as $label => $hits)
            <x-td.panel :title="$label" flush>
                <ul class="hm-rows" data-search-group>
                    @foreach ($hits as $hit)
                        <li class="hm-row">
                            <div>
                                <a class="hm-row__link" href="{{ $hit->url }}">{{ \App\Support\Search\Highlight::mark($hit->title, $words) }}</a>
                                @if ($hit->ref)<span class="hm-row__meta">{{ $hit->ref }}</span>@endif
                            </div>
                            @if ($hit->tag)<div class="hm-row__end"><span class="hm-tag">{{ $hit->tag }}</span></div>@endif
                        </li>
                    @endforeach
                </ul>
            </x-td.panel>
        @endforeach
    </div>
@endif
