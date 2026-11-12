{{-- Title row and tabs shared by the safeguarding screens. --}}
<x-td.page-head :title="$title" :lede="$lede ?? null">
    @isset($actions)<x-slot:actions>{!! $actions !!}</x-slot:actions>@endisset
</x-td.page-head>
@include('ops.partials.tabs', ['label' => 'Safeguarding', 'items' => $tabs])
