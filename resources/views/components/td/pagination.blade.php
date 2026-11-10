{{-- Pagination for any Laravel paginator. Shows where you are in words and in pages. --}}
@props(['paginator', 'label' => 'Pages'])
@if ($paginator->hasPages())
@php
    $current = $paginator->currentPage();
    $last = method_exists($paginator, 'lastPage') ? $paginator->lastPage() : null;
    $window = $last ? collect(range(max(1, $current - 1), min($last, $current + 1)))->merge([1, $last])->unique()->sort()->values() : collect();
@endphp
<nav {{ $attributes->class(['td-pagination']) }} aria-label="{{ $label }}">
    <p class="td-pagination__summary" style="margin:0">
        @if ($last)
            Showing {{ $paginator->firstItem() }} to {{ $paginator->lastItem() }} of {{ $paginator->total() }}
        @else
            Page {{ $current }}
        @endif
    </p>
    <ul class="td-pagination__pages">
        <li>
            @if ($paginator->onFirstPage())
                <span class="td-pagination__page" aria-disabled="true"><x-td.icon name="chevron-left" /><span class="td-sr">Previous page</span></span>
            @else
                <a class="td-pagination__page" href="{{ $paginator->previousPageUrl() }}" rel="prev"><x-td.icon name="chevron-left" /><span class="td-sr">Previous page</span></a>
            @endif
        </li>
        @foreach ($window as $i => $page)
            @if ($i > 0 && $page - $window[$i - 1] > 1)
                <li><span class="td-pagination__page" aria-hidden="true">…</span></li>
            @endif
            <li>
                @if ($page === $current)
                    <span class="td-pagination__page" aria-current="page"><span class="td-sr">Page </span>{{ $page }}</span>
                @else
                    <a class="td-pagination__page" href="{{ $paginator->url($page) }}"><span class="td-sr">Page </span>{{ $page }}</a>
                @endif
            </li>
        @endforeach
        <li>
            @if ($paginator->hasMorePages())
                <a class="td-pagination__page" href="{{ $paginator->nextPageUrl() }}" rel="next"><x-td.icon name="chevron-right" /><span class="td-sr">Next page</span></a>
            @else
                <span class="td-pagination__page" aria-disabled="true"><x-td.icon name="chevron-right" /><span class="td-sr">Next page</span></span>
            @endif
        </li>
    </ul>
</nav>
@endif
