{{-- Data table in a labelled horizontal scroll region (phones scroll the table, never the page).
     caption is required and names the region. Put <thead>/<tbody> in the slot; give number cells class="is-num".
     dense=true uses the business step for this table only. --}}
@props(['caption', 'dense' => false, 'captionHidden' => false])
<div class="table-scroll" role="region" aria-label="{{ $caption }}" tabindex="0" @if ($dense) data-td-step="dense" @endif>
    <table {{ $attributes->class(['td-table']) }}>
        <caption @class(['td-sr' => $captionHidden])>{{ $caption }}</caption>
        {{ $slot }}
    </table>
</div>
