{{-- Wave-1 functional operator view (English only, staff screen), rendered from OperatorResources. --}}
@php($options = collect($config['fields'])->filter(fn ($f) => $f['type'] === 'select')->map(fn ($f) => ($f['options'])())->all())
<x-app-layout>
<div class="t-page">
    <p class="t-hint"><a href="{{ route('operator.academic.home') }}">Academic structure</a></p>
    <h1 class="t-h1">{{ $config['title'] }}</h1>

    @if(session('status'))<div class="t-flash" role="status">{{ session('status') }}</div>@endif
    @if($errors->any())
        <div class="t-flash" role="alert">
            @foreach($errors->all() as $error)<div>{{ $error }}</div>@endforeach
        </div>
    @endif

    @if(! empty($config['filters']))
        <form method="GET" action="{{ route('operator.academic.index', $key) }}" class="t-panel" style="display:flex;flex-wrap:wrap;gap:1rem;align-items:flex-end;">
            @foreach($config['filters'] as $column => $filter)
                <div style="min-width:12rem;flex:1;">
                    <label class="t-label" for="filter-{{ $column }}">{{ $filter['label'] }}</label>
                    <select class="t-field" id="filter-{{ $column }}" name="{{ $column }}">
                        <option value="">All</option>
                        @foreach(($filter['options'])() as $value => $label)
                            <option value="{{ $value }}" @selected((string) request($column) === (string) $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
            @endforeach
            <button type="submit" class="t-btn t-btn-quiet">Filter</button>
        </form>
    @endif

    <details class="t-panel">
        <summary class="t-h2" style="cursor:pointer;">Add</summary>
        <form method="POST" action="{{ route('operator.academic.store', ['resource' => $key] + request()->only(array_keys($config['filters'] ?? []))) }}" id="create-form">@csrf</form>
        @foreach($config['fields'] as $name => $field)
            @include('academic::operator._field', ['prefix' => 'new', 'form' => 'create-form', 'model' => null])
        @endforeach
        <button type="submit" form="create-form" class="t-btn t-btn-primary">Add</button>
    </details>

    <div class="t-panel table-scroll" style="padding:0;overflow-x:auto;" role="region" aria-label="{{ $config['title'] }}" tabindex="0">
        <table class="t-table">
            <thead><tr>
                @foreach($config['columns'] as $column)<th>{{ \Illuminate\Support\Str::headline(str_replace('.', ' ', $column)) }}</th>@endforeach
                @if($hasActiveFlag)<th>State</th>@endif
                <th></th>
            </tr></thead>
            <tbody>
            @forelse($rows as $row)
                <tr>
                    @foreach($config['columns'] as $column)
                        @php($cell = data_get($row, $column))
                        <td>{{ is_bool($cell) ? ($cell ? 'Yes' : 'No') : ($cell instanceof \BackedEnum ? $cell->value : $cell) }}</td>
                    @endforeach
                    @if($hasActiveFlag)
                        <td><span class="t-state {{ $row->is_active ? 't-state-approved' : '' }}">{{ $row->is_active ? 'Active' : 'Inactive' }}</span></td>
                    @endif
                    <td style="white-space:nowrap;">
                        <details>
                            <summary style="cursor:pointer;">Edit</summary>
                            <form method="POST" action="{{ route('operator.academic.update', [$key, $row->getKey()]) }}" id="edit-{{ $row->getKey() }}">@csrf @method('PUT')</form>
                            <div style="min-width:18rem;white-space:normal;">
                                @foreach($config['fields'] as $name => $field)
                                    @include('academic::operator._field', ['prefix' => 'row'.$row->getKey(), 'form' => 'edit-'.$row->getKey(), 'model' => $row])
                                @endforeach
                                <button type="submit" form="edit-{{ $row->getKey() }}" class="t-btn t-btn-primary">Save</button>
                            </div>
                        </details>
                        @if($hasActiveFlag)
                            <form method="POST" action="{{ route('operator.academic.toggle', [$key, $row->getKey()]) }}" style="display:inline;">
                                @csrf
                                <button type="submit" class="t-btn {{ $row->is_active ? 't-btn-danger' : 't-btn-quiet' }}" style="padding:.35rem .7rem;">{{ $row->is_active ? 'Deactivate' : 'Reactivate' }}</button>
                            </form>
                        @endif
                        @if($key === 'offerings')
                            <a href="{{ route('operator.academic.index', ['resource' => 'topics', 'curriculum_subject_level_id' => $row->getKey()]) }}">Topics</a>
                        @endif
                    </td>
                </tr>
            @empty
                <tr><td colspan="{{ count($config['columns']) + 2 }}" style="padding:2rem;">
                    Nothing here yet{{ request()->query() ? ' for this filter' : '' }}. Add the first row above, or run <span class="t-mono">php artisan academic:sync</span> for the reference data.
                </td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
    {{ $rows->links() }}
</div>
</x-app-layout>
