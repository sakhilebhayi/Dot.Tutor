{{-- Report a post. Opens in place (no modal); the report goes to the safety moderation queue. --}}
<details class="text-sm">
    <summary class="inline-flex cursor-pointer list-none items-center gap-1 text-td-ink-2 [&::-webkit-details-marker]:hidden"><x-td.icon name="flag" /><span>Report</span></summary>
    <form method="POST" action="{{ route('rooms.posts.report', $target) }}" class="mt-3 grid max-w-md gap-3">
        @csrf
        <x-td.field label="What is wrong?" for="category-{{ $target->id }}">
            <x-td.select name="category" id="category-{{ $target->id }}" :options="collect($categories)->mapWithKeys(fn ($c) => [$c->value => $c->label()])->all()" />
        </x-td.field>
        <x-td.field label="Anything a moderator should know" for="details-{{ $target->id }}" optional>
            <x-td.textarea name="details" id="details-{{ $target->id }}" rows="2" maxlength="2000" />
        </x-td.field>
        <div><x-td.button type="submit" variant="secondary" size="sm" icon="flag">Send report</x-td.button></div>
    </form>
</details>
