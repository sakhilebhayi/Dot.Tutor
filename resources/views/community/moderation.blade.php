@php use App\Domain\Community\Support\Names; @endphp
<x-layouts::operations title="Held room posts">
    <x-td.page-head title="Held room posts" lede="Posts from learners under 13 wait here before anyone else sees them, and so do posts held after reports. Reports themselves are worked in the safeguarding queue." />
    <x-td.panel title="Waiting for a decision" flush>
        @if ($held->isEmpty())
            <x-td.state kind="success" title="Nothing is waiting">Every held post has been reviewed.</x-td.state>
        @else
            @foreach ($held as $post)
                @php($original = $post->original_text ? json_decode($post->original_text, true) : null)
                <article class="grid gap-2 border-b border-td-line px-5 py-4 last:border-b-0">
                    <p class="m-0 text-sm text-td-ink-2">{{ $post->room?->displayName() }} · {{ \App\Domain\Community\Models\Post::kindLabel($post->kind) }} by {{ Names::short($post->author) }} ·
                        {{ $post->held_reason === 'pre_moderation' ? 'Learner under 13' : ($post->held_reason === 'reported_child_safety' ? 'Child-safety report' : $post->reporters_count.' reports') }}
                        · held {{ $post->updated_at?->diffForHumans() }}</p>
                    @if ($post->title)<p class="m-0 font-semibold">{{ $post->title }}</p>@endif
                    <div class="whitespace-pre-line" style="max-width: var(--td-measure)">{{ $post->body }}</div>
                    @if ($original)
                        <details class="text-sm"><summary class="cursor-pointer">Original text, before contact details were hidden</summary><div class="mt-2 whitespace-pre-line">{{ $original['body'] ?? '' }}</div></details>
                    @endif
                    <div class="flex flex-wrap gap-2">
                        <form method="POST" action="{{ route('rooms.moderation.decide', $post) }}">@csrf<input type="hidden" name="decision" value="release"><x-td.button type="submit" size="sm" variant="primary">Release</x-td.button></form>
                        <form method="POST" action="{{ route('rooms.moderation.decide', $post) }}">@csrf<input type="hidden" name="decision" value="remove"><x-td.button type="submit" size="sm" variant="danger">Remove</x-td.button></form>
                    </div>
                </article>
            @endforeach
            @if ($held->hasPages())<div class="px-5 py-3"><x-td.pagination :paginator="$held" label="Held post pages" /></div>@endif
        @endif
    </x-td.panel>
</x-layouts::operations>
