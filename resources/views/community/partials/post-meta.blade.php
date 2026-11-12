{{-- Who wrote a post, when, in which language, and whether it was edited or had contact details hidden. --}}
@php use App\Domain\Community\Support\Names; @endphp
<div class="flex flex-wrap items-center gap-x-3 gap-y-1 text-sm text-td-ink-2">
    <x-td.avatar :name="Names::short($p->author)" size="sm" decorative />
    <span class="font-semibold text-td-ink">{{ Names::short($p->author) }}</span>
    <time datetime="{{ $p->created_at?->toIso8601String() }}">{{ $p->created_at?->diffForHumans() }}</time>
    <span lang="{{ $p->original_locale }}">{{ $languageNames[$p->original_locale] ?? strtoupper($p->original_locale) }}</span>
    @if ($p->edited_at)
        <a class="td-link" href="{{ route('rooms.posts.history', $p) }}">Edited</a>
    @endif
    @if ($p->redactions > 0)
        <span><x-td.icon name="lock" /> Contact details hidden</span>
    @endif
</div>
