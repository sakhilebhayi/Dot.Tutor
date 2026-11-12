{{-- A paginated list of threads with its empty state. Expects $threads, $empty, $emptyBody, $emptyAction, $languageNames, $languageCodes. --}}
@if ($threads->isEmpty())
    <x-td.state kind="empty" :title="$empty">
        {{ $emptyBody }}
        @if ($emptyAction && $canParticipate)
            <x-slot:actions><x-td.button :href="$emptyAction['href']" variant="primary">{{ $emptyAction['label'] }}</x-td.button></x-slot:actions>
        @endif
    </x-td.state>
@else
    <ul class="m-0 list-none p-0" aria-label="Threads">
        @foreach ($threads as $post)
            @include('community.partials.thread-row', ['post' => $post, 'showLanguage' => count($languageCodes) > 1, 'languageNames' => $languageNames])
        @endforeach
    </ul>
    @if ($threads->hasPages())
        <div class="border-t border-td-line px-5 py-3"><x-td.pagination :paginator="$threads" label="Thread pages" /></div>
    @endif
@endif
