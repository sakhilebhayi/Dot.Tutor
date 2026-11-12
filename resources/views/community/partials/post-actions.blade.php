{{-- Edit, remove, translate and report for one post. --}}
@php($mine = $p->author_id === auth()->id())
<div class="flex flex-wrap items-start gap-x-4 gap-y-2 text-sm">
    @if ($mine && in_array($p->status, ['visible', 'held'], true))
        <a class="td-link" href="{{ route('rooms.posts.edit', $p) }}">Edit</a>
        <form method="POST" action="{{ route('rooms.posts.destroy', $p) }}">@csrf @method('DELETE')<button type="submit" class="td-link cursor-pointer border-0 bg-transparent px-0 py-[3px] text-sm">Remove</button></form>
    @elseif ($isOperator && $p->status === 'visible')
        <form method="POST" action="{{ route('rooms.posts.destroy', $p) }}">@csrf @method('DELETE')<button type="submit" class="td-link cursor-pointer border-0 bg-transparent px-0 py-[3px] text-sm">Remove as moderator</button></form>
    @endif
    @if ($translationAvailable && $p->isVisible() && $p->original_locale !== $translateTo)
        <form method="POST" action="{{ route('ai.translations.store') }}">
            @csrf
            <input type="hidden" name="type" value="community.post"><input type="hidden" name="id" value="{{ $p->id }}">
            <input type="hidden" name="field" value="body"><input type="hidden" name="locale" value="{{ $translateTo }}">
            <button type="submit" class="td-link cursor-pointer border-0 bg-transparent p-0 text-sm">Translate into {{ $languageNames[$translateTo] ?? strtoupper($translateTo) }} (machine translation, original kept)</button>
        </form>
    @endif
    @if (! $mine && $p->isVisible())
        @include('community.partials.report', ['target' => $p, 'categories' => $categories])
    @endif
</div>
