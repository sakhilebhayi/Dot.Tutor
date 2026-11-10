{{-- Wave-1 functional operator view (English only, staff screen). --}}
<x-app-layout>
<div class="t-page">
    <h1 class="t-h1">Academic structure</h1>
    <p class="t-lede">
        Countries, curricula, levels, subjects and languages are data. Changes apply at once to onboarding, matching and
        "describe what you need". Nothing here is deleted: deactivate instead, and everything that already uses a row keeps it.
        Reference data is inserted by <span class="t-mono">php artisan academic:sync</span>, which never overwrites an edit made here.
    </p>
    <div class="t-panel table-scroll" style="padding:0;overflow-x:auto;" role="region" aria-label="Academic structure" tabindex="0">
        <table class="t-table">
            <thead><tr><th>Area</th><th style="text-align:right;">Rows</th></tr></thead>
            <tbody>
            @foreach($resources as $resource)
                <tr>
                    <td><a href="{{ route('operator.academic.index', $resource['key']) }}">{{ $resource['title'] }}</a></td>
                    <td class="t-mono" style="text-align:right;">{{ $resource['count'] }}</td>
                </tr>
            @endforeach
                <tr>
                    <td><a href="{{ route('operator.academic.teaching-languages.index') }}">Tutors' teaching languages (verification)</a></td>
                    <td></td>
                </tr>
            </tbody>
        </table>
    </div>
</div>
</x-app-layout>
