<x-layouts::learner title="Edit history" :step="$step">
    <nav aria-label="Breadcrumb" class="mb-3 text-sm"><a class="td-link" href="{{ route('rooms.posts.show', $post->isThread() ? $post : $post->parent_id) }}">Back to the post</a></nav>
    <x-td.page-head title="Edit history" lede="Earlier versions of this post, newest first. The current text is on the post itself." />
    <x-td.panel title="Earlier versions" flush>
        @forelse ($revisions as $revision)
            <article class="grid gap-2 border-b border-td-line px-5 py-4 last:border-b-0">
                <p class="m-0 text-sm text-td-ink-2">Replaced <time datetime="{{ $revision->created_at?->toIso8601String() }}">{{ $revision->created_at?->format('j M Y, H:i') }}</time></p>
                @if ($revision->title)<p class="m-0 font-semibold">{{ $revision->title }}</p>@endif
                <div class="whitespace-pre-line" style="max-width: var(--td-measure)">{{ $revision->body }}</div>
            </article>
        @empty
            <x-td.state kind="empty" title="This post has not been edited" />
        @endforelse
    </x-td.panel>
</x-layouts::learner>
