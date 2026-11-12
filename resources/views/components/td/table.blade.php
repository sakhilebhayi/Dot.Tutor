{{-- Data table in a labelled horizontal scroll region (phones scroll the table, never the page).
     caption is required and names the region. Put <thead>/<tbody> in the slot; give number cells class="is-num".
     dense=true uses the business step for this table only.
     stack=true turns each row into a card under 40rem (resources/js/ds/table-stack.js, D-2202): mark the header of the
     figure that matters with data-stack="key", the buttons' column with data-stack="action", long text with
     data-stack="wide"; the first column is the title. Without script the table scrolls in its region as before. --}}
@props(['caption', 'dense' => false, 'captionHidden' => false, 'stack' => false])
<div class="table-scroll" role="region" aria-label="{{ $caption }}" tabindex="0" @if ($dense) data-td-step="dense" @endif>
    <table {{ $attributes->class(['td-table', 'td-table--stack' => $stack]) }} @if ($stack) data-td-stack @endif>
        <caption @class(['td-sr' => $captionHidden])>{{ $caption }}</caption>
        {{ $slot }}
    </table>
</div>
