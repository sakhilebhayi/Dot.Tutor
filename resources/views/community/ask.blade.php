@php
    use App\Domain\Community\Models\Post;
    $copy = [
        Post::QUESTION => ['Ask a question', 'Your question', 'Say what you tried and where you got stuck. People answer faster when they can see your working.', 'Post the question'],
        Post::DISCUSSION => ['Start a discussion', 'Topic', 'What do you want to talk through with others at your level?', 'Start the discussion'],
        Post::EXPLANATION => ['Share an explanation', 'What you are explaining', 'Explain it the way you wish someone had explained it to you.', 'Share the explanation'],
        Post::TECHNIQUE => ['Share a revision technique', 'The technique', 'How you revise this subject, and why it works for you.', 'Share the technique'],
        Post::MATERIAL => ['Share library material', 'Title', 'Why it is useful, and for which part of the work.', 'Share it'],
    ][$kind];
@endphp
<x-layouts::learner :title="$copy[0].' · '.$room->displayName()" :step="$step">
    <nav aria-label="Breadcrumb" class="mb-3 text-sm"><a class="td-link" href="{{ route('rooms.show', $room) }}">{{ $room->displayName() }}</a> <span class="text-td-ink-2">/ {{ $copy[0] }}</span></nav>
    <x-td.page-head :title="$copy[0]" :lede="'In '.$room->displayName().'. Everyone here is learning the same thing as you.'" />

    <div class="td-panels td-panels--2">
        <x-td.panel :title="$copy[0]" heading-level="2">
            @unless ($canParticipate)
                <x-td.notice tone="warning" title="You cannot post in this room">Your account cannot post here right now. A parent or guardian may need to link to your account first.</x-td.notice>
            @else
                @if ($errors->has('community') || $errors->has('safety'))
                    <x-td.notice tone="danger" class="mb-4">{{ $errors->first('community') ?: $errors->first('safety') }}</x-td.notice>
                @endif
                <form method="POST" action="{{ route('rooms.posts.store', $room) }}" class="grid gap-5">
                    @csrf
                    <input type="hidden" name="kind" value="{{ $kind }}">
                    <nav aria-label="What are you posting?" class="flex flex-wrap gap-x-4 gap-y-2 text-sm">
                        @foreach (Post::THREAD_KINDS as $k)
                            <a class="td-link" href="{{ route('rooms.ask', [$room, 'kind' => $k]) }}" @if ($k === $kind) aria-current="page" style="text-decoration-thickness:2px" @endif>{{ Post::kindLabel($k) }}</a>
                        @endforeach
                    </nav>

                    <x-td.field :label="$copy[1]" for="title" :error="$errors->first('title')" hint="One line. At least five characters.">
                        <x-td.input name="title" id="title" maxlength="{{ config('community.max_title') }}" required :hint="true" />
                    </x-td.field>

                    @if ($kind === Post::MATERIAL)
                        <x-td.field label="Library item" for="library_resource_id" :error="$errors->first('library_resource_id')" hint="Material is stored in the library with its licence. Share one of your published items, or upload new material to the library first.">
                            @if ($myResources->isEmpty())
                                <p class="m-0 text-sm text-td-ink-2">You have no published library items yet.
                                    @if (Route::has('learning.library.index'))<a class="td-link" href="{{ route('learning.library.index') }}">Upload to the library</a>@endif</p>
                                <x-td.input type="number" name="library_resource_id" id="library_resource_id" min="1" placeholder="Or enter a library item number" :hint="true" />
                            @else
                                <x-td.select name="library_resource_id" id="library_resource_id" :options="$myResources->mapWithKeys(fn ($r) => [$r->id => $r->title])->all()" :hint="true" />
                            @endif
                        </x-td.field>
                    @endif

                    <x-td.field :label="$kind === Post::QUESTION ? 'Details' : 'Write it here'" for="body" :error="$errors->first('body')" :hint="$copy[2]">
                        <x-td.textarea name="body" id="body" rows="8" maxlength="{{ config('community.max_body') }}" required :hint="true" />
                    </x-td.field>

                    <x-td.field label="Language you are writing in" for="original_locale" hint="Others see your words as you wrote them, and can ask for a translation.">
                        <x-td.select name="original_locale" id="original_locale" :value="old('original_locale', $defaultLocale)" :options="$languages->mapWithKeys(fn ($l) => [$l->code => ($l->name_native && $l->name_native !== $l->name_en ? $l->name_native.' ('.$l->name_en.')' : $l->name_en)])->all()" :hint="true" />
                    </x-td.field>

                    <div class="flex flex-wrap items-center gap-3">
                        <x-td.button type="submit" variant="primary">{{ $copy[3] }}</x-td.button>
                        <x-td.button :href="route('rooms.show', $room)" variant="quiet">Cancel</x-td.button>
                    </div>
                </form>
            @endunless
        </x-td.panel>

        <x-td.panel title="Before you post" heading-level="2">
            <div class="grid gap-3 text-sm" style="max-width: var(--td-measure)">
                <p class="m-0">Ask for help understanding, not for answers to hand in. Requests to do marked work or exams for someone are removed.</p>
                @if ($isMinorRoom)
                    <p class="m-0">Do not share phone numbers, addresses or social media. They are hidden automatically in this room.</p>
                @endif
                @if ($band === 'child')
                    <p class="m-0">A moderator reads posts from learners under 13 before others see them. Your post appears once a moderator has read it.</p>
                @endif
                <p class="m-0">Need someone to work through it with you? <a class="td-link" href="{{ route('rooms.show', [$room, 'tab' => 'tutors']) }}">Find a tutor for this subject</a>.</p>
            </div>
        </x-td.panel>
    </div>
</x-layouts::learner>
