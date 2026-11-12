<x-layouts::learner title="Edit your post" :step="$step">
    <nav aria-label="Breadcrumb" class="mb-3 text-sm"><a class="td-link" href="{{ route('rooms.posts.show', $post->isThread() ? $post : $post->parent_id) }}">Back to the post</a></nav>
    <x-td.page-head title="Edit your post" lede="The earlier version is kept in the post's history, and people can see that it was edited." />
    <x-td.panel title="Your post">
        @if ($errors->has('community') || $errors->has('safety'))
            <x-td.notice tone="danger" class="mb-4">{{ $errors->first('community') ?: $errors->first('safety') }}</x-td.notice>
        @endif
        <form method="POST" action="{{ route('rooms.posts.update', $post) }}" class="grid max-w-2xl gap-5">
            @csrf @method('PUT')
            @if ($post->isThread())
                <x-td.field label="Title" for="title" :error="$errors->first('title')"><x-td.input name="title" id="title" :value="old('title', $post->title)" maxlength="{{ config('community.max_title') }}" required /></x-td.field>
            @endif
            <x-td.field label="Text" for="body" :error="$errors->first('body')"><x-td.textarea name="body" id="body" rows="10" maxlength="{{ config('community.max_body') }}" required>{{ old('body', $post->body) }}</x-td.textarea></x-td.field>
            <div class="flex gap-3"><x-td.button type="submit" variant="primary">Save changes</x-td.button><x-td.button :href="route('rooms.posts.show', $post->isThread() ? $post : $post->parent_id)" variant="quiet">Cancel</x-td.button></div>
        </form>
    </x-td.panel>
</x-layouts::learner>
