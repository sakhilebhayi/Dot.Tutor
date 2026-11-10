@props(['id' => null, 'maxWidth' => null])

<x-modal :id="$id" :maxWidth="$maxWidth" {{ $attributes }}>
    <div class="px-6 pt-6 pb-4">
        <div class="flex items-start gap-4">
            <span class="td-state__glyph" style="background:var(--td-danger-soft);color:var(--td-danger-ink)"><x-td.icon name="triangle-alert" /></span>
            <div>
                <h3 class="m-0 text-lg font-bold text-td-ink">
                    {{ $title }}
                </h3>

                <div class="mt-3 text-td-ink-2">
                    {{ $content }}
                </div>
            </div>
        </div>
    </div>

    <div class="flex flex-row flex-wrap justify-end gap-3 px-6 py-4 bg-td-sunken border-t border-td-line">
        {{ $footer }}
    </div>
</x-modal>
