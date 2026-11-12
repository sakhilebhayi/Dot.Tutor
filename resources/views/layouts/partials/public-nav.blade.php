{{-- The landing's sections, shared by the public header and the error pages (no session or database needed).
     Below 56rem the links fold behind the Menu button (resources/js/ds/public-menu.js, fix round D-2300). Without
     script the button stays hidden and the links show as a scrolling row under the wordmark. --}}
@php $onHome = request()->is('/'); @endphp
<nav id="td-public-nav" class="td-public-nav" aria-label="Dot.Tutor">
    <a href="{{ $onHome ? '#how' : url('/#how') }}">How matching works</a>
    <a href="{{ $onHome ? '#rooms' : url('/#rooms') }}">Rooms by level</a>
    <a href="{{ $onHome ? '#languages' : url('/#languages') }}">Languages</a>
    <a href="{{ $onHome ? '#teach' : url('/#teach') }}">Teach</a>
</nav>
