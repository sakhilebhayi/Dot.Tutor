{{-- Title row and tabs shared by the safeguarding screens. --}}
<x-td.page-head :title="$title" :lede="$lede ?? null" />
@include('ops.partials.tabs', ['label' => 'Safeguarding', 'items' => $tabs])
