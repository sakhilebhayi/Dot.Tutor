@props(['id' => null, 'maxWidth' => null])

<x-modal :id="$id" :maxWidth="$maxWidth" {{ $attributes }}>
    <div class="px-6 py-5">
        <div class="text-lg font-bold text-td-ink">
            {{ $title }}
        </div>

        <div class="mt-4 text-td-ink-2">
            {{ $content }}
        </div>
    </div>

    <div class="flex flex-row flex-wrap justify-end gap-3 px-6 py-4 bg-td-sunken border-t border-td-line">
        {{ $footer }}
    </div>
</x-modal>
