{{-- One thread in a room list: what it is, whether it is answered, who started it, how many replies. --}}
@php
    use App\Domain\Community\Models\Post;
    use App\Domain\Community\Support\Names;
    $isQuestion = $post->kind === Post::QUESTION;
@endphp
<li class="grid gap-1 border-b border-td-line px-5 py-4 last:border-b-0">
    <div class="flex flex-wrap items-center gap-2 text-sm">
        @if ($post->status === Post::HELD)
            <span class="td-badge td-badge--review"><x-td.icon name="hourglass" />Waiting for a moderator</span>
        @elseif ($isQuestion && $post->isResolved())
            <span class="td-badge td-badge--verified"><x-td.icon name="circle-check" />Resolved</span>
        @elseif ($isQuestion && $post->replies_count === 0)
            <span class="td-badge td-badge--self"><x-td.icon name="circle-help" />Waiting for an answer</span>
        @elseif (! $isQuestion)
            <span class="td-badge td-badge--peer">{{ Post::kindLabel($post->kind) }}</span>
        @endif
        @if ($showLanguage ?? false)
            <span class="text-td-ink-2" lang="{{ $post->original_locale }}">{{ $languageNames[$post->original_locale] ?? strtoupper($post->original_locale) }}</span>
        @endif
    </div>
    <a href="{{ route('rooms.posts.show', $post) }}" class="td-link text-lg" lang="{{ $post->original_locale }}">{{ $post->title }}</a>
    <p class="m-0 text-sm text-td-ink-2">
        {{ Names::short($post->author) }}
        · {{ $isQuestion ? trans_choice('{0} No answers yet|{1} 1 answer|[2,*] :count answers', $post->replies_count) : trans_choice('{0} No replies yet|{1} 1 reply|[2,*] :count replies', $post->replies_count) }}
        · <time datetime="{{ $post->last_activity_at?->toIso8601String() }}">{{ $post->last_activity_at?->diffForHumans() }}</time>
    </p>
</li>
