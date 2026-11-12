{{-- Ops restyle of the academic agent's operator home (same data: $resources). The academic controller is unchanged. --}}
<x-layouts::operations title="Academic structure">
    <x-td.page-head title="Academic structure" lede="Countries, curricula, levels, subjects and languages are data. Changes apply at once to onboarding, matching and describe-what-you-need. Nothing is deleted: deactivate instead, and everything that already uses a row keeps it." />
    <div class="grid gap-6">
        <x-td.panel flush>
            <x-td.table caption="Academic structure areas" caption-hidden>
                <thead><tr><th scope="col">Area</th><th scope="col" class="is-num">Rows</th></tr></thead>
                <tbody>
                    @foreach ($resources as $resource)
                        <tr><td><a href="{{ route('operator.academic.index', $resource['key']) }}">{{ $resource['title'] }}</a></td><td class="is-num">{{ number_format($resource['count']) }}</td></tr>
                    @endforeach
                    <tr><td><a href="{{ route('operator.academic.teaching-languages.index') }}">Tutors' teaching languages (verification)</a></td><td class="is-num">—</td></tr>
                </tbody>
            </x-td.table>
        </x-td.panel>
        <p class="m-0 text-sm ops-muted">Reference data is inserted by php artisan academic:sync, which never overwrites an edit made here. The names were written from public curriculum documents and still need an academic editor's review.</p>
    </div>
</x-layouts::operations>
