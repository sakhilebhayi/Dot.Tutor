<x-app-layout>
    <x-slot name="header">Courses</x-slot>
    @include('learning.partials.styles')
    <div class="ln">
        <h1>Courses</h1>
        <p class="muted">Courses published by tutors on Dot.Tutor. Prices are set by each tutor.</p>
        <form method="GET" action="{{ route('learning.courses.index') }}" class="row" role="search">
            <label for="q" class="sr-only">Search courses</label>
            <input id="q" name="q" type="search" value="{{ $filters['q'] ?? '' }}" placeholder="Search by title or description" style="max-width:24rem">
            <label for="pricing" class="sr-only">Price</label>
            <select id="pricing" name="pricing" style="max-width:10rem">
                <option value="">Free and paid</option>
                <option value="free" @selected(($filters['pricing'] ?? '') === 'free')>Free</option>
                <option value="paid" @selected(($filters['pricing'] ?? '') === 'paid')>Paid</option>
            </select>
            <button class="btn ghost" type="submit">Search</button>
        </form>
        @if ($courses->isEmpty())
            <p class="muted" style="margin-top:2rem">No published courses match. Tutors publish courses from the <a href="{{ route('learning.studio.courses.index') }}">course studio</a>.</p>
        @else
            <ul class="plain" style="margin-top:1.5rem">
                @foreach ($courses as $course)
                    <li>
                        <a href="{{ route('learning.courses.show', $course['id']) }}"><strong>{{ $course['title'] }}</strong></a>
                        <div class="row muted">
                            <span>{{ $course['tutor']['name'] }}</span>
                            <span>{{ $course['academic']['subject'] ?? 'Subject not set' }}</span>
                            <span>Language: {{ $course['language'] }}</span>
                            <span>{{ $course['pricing'] === 'free' ? 'Free' : '' }}@if ($course['pricing'] === 'paid')@include('learning.partials.money', ['minor' => $course['price_minor'], 'currency' => $course['currency']])@endif</span>
                        </div>
                    </li>
                @endforeach
            </ul>
            {{ $paginator->links() }}
        @endif
    </div>
</x-app-layout>
