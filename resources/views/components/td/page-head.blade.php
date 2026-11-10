{{-- The title row of a signed-in page: the page's h1, one sentence, and its actions. --}}
@props(['title', 'lede' => null])
<div {{ $attributes->class(['td-page-head']) }}>
    <div>
        <h1 class="td-page-head__title">{{ $title }}</h1>
        @if ($lede)<p class="td-page-head__lede">{{ $lede }}</p>@endif
    </div>
    @isset($actions)<div class="td-page-head__actions">{{ $actions }}</div>@endisset
</div>
