@php
    use App\Domain\Community\Models\Post;
    use App\Domain\Community\Support\Names;
    $isQuestion = $post->kind === Post::QUESTION;
    $me = auth()->user();
    $answers = $children;
@endphp
<x-layouts::learner :title="$post->title" :step="$step">
    <nav aria-label="Breadcrumb" class="mb-3 text-sm">
        <a class="td-link" href="{{ route('rooms.show', $room) }}">{{ $room->displayName() }}</a>
        <span class="text-td-ink-2">/ {{ Post::kindLabel($post->kind) }}</span>
    </nav>

    <div class="td-page-head">
        <div>
            <div class="mb-2 flex flex-wrap items-center gap-2 text-sm">
                @if ($post->status === Post::HELD)
                    <span class="td-badge td-badge--review"><x-td.icon name="hourglass" />Waiting for a moderator</span>
                @elseif ($isQuestion && $post->isResolved())
                    <span class="td-badge td-badge--verified"><x-td.icon name="circle-check" />Resolved</span>
                @elseif ($isQuestion)
                    <span class="td-badge td-badge--self"><x-td.icon name="circle-help" />Open question</span>
                @else
                    <span class="td-badge td-badge--peer">{{ Post::kindLabel($post->kind) }}</span>
                @endif
            </div>
            <h1 class="td-page-head__title" lang="{{ $post->original_locale }}">{{ $post->title }}</h1>
        </div>
        @if ($canParticipate && $post->isVisible())
            <div class="td-page-head__actions">
                <x-td.button href="#reply" variant="primary" icon="pencil">{{ $isQuestion ? 'Answer' : 'Reply' }}</x-td.button>
            </div>
        @endif
    </div>

    @if ($post->status === Post::HELD)
        <x-td.notice tone="info" class="mb-6" title="Only you can see this for now">{{ $post->heldLabel() }}</x-td.notice>
    @endif

    <div class="td-panels td-panels--2">
        <div class="td-panel">
            <article class="td-panel__body grid gap-4" id="post-{{ $post->id }}" aria-label="{{ Post::kindLabel($post->kind) }}">
                @include('community.partials.post-meta', ['p' => $post])
                <div class="whitespace-pre-line text-lg" style="max-width: var(--td-measure)" lang="{{ $post->original_locale }}">{{ $post->body }}</div>

                @if ($resource)
                    <div class="border border-td-line bg-td-sunken p-4">
                        <p class="td-label m-0">From the library</p>
                        <a class="td-link" href="{{ route('learning.library.show', $resource->id) }}">{{ $resource->title }}</a>
                        <p class="m-0 text-sm text-td-ink-2">{{ ucfirst(str_replace('_', ' ', $resource->type?->value ?? '')) }} · licence {{ str_replace('_', ' ', strtoupper($resource->licence?->value ?? '')) }}</p>
                    </div>
                @endif

                @include('community.partials.post-actions', ['p' => $post])
            </article>

            <section class="border-t border-td-line" aria-labelledby="replies-title">
                <div class="td-panel__head">
                    <h2 class="td-panel__title" id="replies-title">
                        {{ $isQuestion ? trans_choice('{0} No answers yet|{1} 1 answer|[2,*] :count answers', $answers->count()) : trans_choice('{0} No replies yet|{1} 1 reply|[2,*] :count replies', $answers->count()) }}
                    </h2>
                </div>
                @foreach ($answers as $answer)
                    @php($accepted = $answer->id === $post->accepted_answer_id)
                    <article id="post-{{ $answer->id }}" @class(['grid gap-3 border-t border-td-line px-5 py-4', 'bg-td-primary-soft' => $accepted]) aria-label="{{ $accepted ? 'Accepted answer' : Post::kindLabel($answer->kind) }}">
                        @if ($accepted)
                            <p class="m-0 flex items-center gap-2 font-semibold"><x-td.icon name="circle-check" />Accepted answer</p>
                        @endif
                        @include('community.partials.post-meta', ['p' => $answer])
                        <div class="whitespace-pre-line" style="max-width: var(--td-measure)" lang="{{ $answer->original_locale }}">{{ $answer->body }}</div>
                        @if ($answer->status === Post::HELD)
                            <p class="m-0 text-sm text-td-ink-2">{{ $answer->heldLabel() }}</p>
                        @endif
                        <div class="flex flex-wrap items-center gap-3">
                            @if ($mayResolve && ! $accepted && $answer->isVisible() && $answer->kind === Post::ANSWER)
                                <form method="POST" action="{{ route('rooms.posts.accept', $answer) }}">@csrf<x-td.button type="submit" size="sm" variant="secondary" icon="check">Accept this answer</x-td.button></form>
                            @endif
                            @include('community.partials.post-actions', ['p' => $answer])
                        </div>
                    </article>
                @endforeach
                @if ($answers->isEmpty())
                    <p class="m-0 border-t border-td-line px-5 py-4 text-td-ink-2">{{ $isQuestion ? 'Nobody has answered yet. If you know part of the answer, share that part.' : 'No replies yet.' }}</p>
                @endif
            </section>

            @if ($canParticipate && $post->isVisible())
                <section class="border-t border-td-line px-5 py-5" id="reply" aria-labelledby="reply-title">
                    <h2 class="td-panel__title mb-3 mt-0" id="reply-title">{{ $isQuestion ? 'Your answer' : 'Your reply' }}</h2>
                    @if ($errors->has('community') || $errors->has('safety'))
                        <x-td.notice tone="danger" class="mb-4">{{ $errors->first('community') ?: $errors->first('safety') }}</x-td.notice>
                    @endif
                    <form method="POST" action="{{ route('rooms.posts.reply', $post) }}" class="grid gap-4">
                        @csrf
                        <x-td.field :label="$isQuestion ? 'Answer' : 'Reply'" for="reply-body" :error="$errors->first('body')" :hint="$isQuestion ? 'Explain the steps, so the person who asked can do the next one alone.' : null">
                            <x-td.textarea name="body" id="reply-body" rows="6" maxlength="{{ config('community.max_body') }}" required :hint="$isQuestion" />
                        </x-td.field>
                        <input type="hidden" name="original_locale" value="{{ $translateTo }}">
                        <div><x-td.button type="submit" variant="primary">{{ $isQuestion ? 'Post the answer' : 'Post the reply' }}</x-td.button></div>
                    </form>
                </section>
            @endif
        </div>

        <aside class="td-panel" aria-labelledby="post-side-title">
            <div class="td-panel__head"><h2 class="td-panel__title" id="post-side-title">In this room</h2></div>
            <div class="td-panel__body grid gap-4">
                @include('community.partials.scope', ['room' => $room])
                @if ($isQuestion)
                    <p class="m-0 text-sm text-td-ink-2">The person who asked, or a tutor in this room, accepts the answer that solved it. The question is then marked resolved.</p>
                    @if ($mayResolve && $post->isVisible())
                        <form method="POST" action="{{ route('rooms.posts.resolve', $post) }}">
                            @csrf
                            <input type="hidden" name="resolved" value="{{ $post->isResolved() ? 0 : 1 }}">
                            <x-td.button type="submit" variant="secondary" size="sm" :icon="$post->isResolved() ? 'circle-help' : 'circle-check'">{{ $post->isResolved() ? 'Open the question again' : 'Mark as resolved' }}</x-td.button>
                        </form>
                    @endif
                @endif
                @if (Route::has('marketplace.find'))
                    <p class="m-0 text-sm"><a class="td-link" href="{{ route('marketplace.find', ['subject_id' => $room->subject_id, 'level_id' => $room->academic_level_id, 'curriculum_id' => $room->curriculum_id]) }}">Request tutoring help with {{ $room->subject?->name }}</a></p>
                @endif
                <p class="m-0 text-sm"><a class="td-link" href="{{ route('rooms.show', $room) }}">Back to the room</a></p>
            </div>
        </aside>
    </div>
</x-layouts::learner>
