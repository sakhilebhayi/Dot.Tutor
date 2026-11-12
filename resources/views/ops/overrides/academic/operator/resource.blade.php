{{-- Ops restyle of the academic agent's generic resource screen (same data: $key, $config, $rows, $hasActiveFlag;
     same forms posting to operator.academic.store / update / toggle). --}}
@php($options = collect($config['fields'])->filter(fn ($f) => $f['type'] === 'select')->map(fn ($f) => ($f['options'])())->all())
<x-layouts::operations :title="$config['title']">
    <x-td.page-head :title="$config['title']" lede="Add, edit or deactivate. Every change is written to the audit log.">
        <x-slot:actions><x-td.button :href="route('operator.academic.home')" icon="arrow-left" variant="quiet">Academic structure</x-td.button></x-slot:actions>
    </x-td.page-head>

    <div class="grid gap-6">
        @if (session('status'))<x-td.notice tone="success" role="status">{{ session('status') }}</x-td.notice>@endif
        @if ($errors->any())
            <x-td.notice tone="danger" title="Not saved">@foreach ($errors->all() as $error)<p class="m-0">{{ $error }}</p>@endforeach</x-td.notice>
        @endif

        @if (! empty($config['filters']))
            <form method="GET" action="{{ route('operator.academic.index', $key) }}" class="ops-act">
                @foreach ($config['filters'] as $column => $filter)
                    <x-td.field :label="$filter['label']" :for="'filter-'.$column">
                        <x-td.select :name="$column" :id="'filter-'.$column" :value="request($column)" placeholder="All" :options="($filter['options'])()" />
                    </x-td.field>
                @endforeach
                <x-td.button type="submit" icon="funnel">Filter</x-td.button>
            </form>
        @endif

        <x-td.panel title="Add">
            <details>
                <summary class="cursor-pointer">Add a row to {{ strtolower($config['title']) }}</summary>
                <form method="POST" action="{{ route('operator.academic.store', ['resource' => $key] + request()->only(array_keys($config['filters'] ?? []))) }}" id="create-form">@csrf</form>
                <div class="grid gap-4 mt-4" style="grid-template-columns: repeat(auto-fit, minmax(min(16rem, 100%), 1fr))">
                    @foreach ($config['fields'] as $name => $field)
                        @include('academic::operator._field', ['prefix' => 'new', 'form' => 'create-form', 'model' => null])
                    @endforeach
                </div>
                <div class="mt-4"><x-td.button type="submit" form="create-form" variant="primary">Add</x-td.button></div>
            </details>
        </x-td.panel>

        <x-td.panel flush>
            <x-td.table :caption="$config['title']" caption-hidden dense>
                <thead><tr>
                    @foreach ($config['columns'] as $column)<th scope="col">{{ \Illuminate\Support\Str::headline(str_replace('.', ' ', $column)) }}</th>@endforeach
                    @if ($hasActiveFlag)<th scope="col">State</th>@endif
                    <th scope="col"><span class="td-sr">Actions</span></th>
                </tr></thead>
                <tbody>
                    @forelse ($rows as $row)
                        <tr>
                            @foreach ($config['columns'] as $column)
                                @php($cell = data_get($row, $column))
                                <td>{{ is_bool($cell) ? ($cell ? 'Yes' : 'No') : ($cell instanceof \BackedEnum ? $cell->value : $cell) }}</td>
                            @endforeach
                            @if ($hasActiveFlag)
                                <td>@include('ops.partials.tag', $row->is_active ? ['text' => 'Active', 'tone' => 'success'] : ['text' => 'Inactive', 'icon' => 'circle-dashed'])</td>
                            @endif
                            <td style="min-width: 14rem">
                                <details>
                                    <summary class="cursor-pointer">Edit</summary>
                                    <form method="POST" action="{{ route('operator.academic.update', [$key, $row->getKey()]) }}" id="edit-{{ $row->getKey() }}">@csrf @method('PUT')</form>
                                    <div class="grid gap-3 mt-3" style="min-width: 18rem">
                                        @foreach ($config['fields'] as $name => $field)
                                            @include('academic::operator._field', ['prefix' => 'row'.$row->getKey(), 'form' => 'edit-'.$row->getKey(), 'model' => $row])
                                        @endforeach
                                        <div><x-td.button type="submit" form="edit-{{ $row->getKey() }}" variant="primary" size="sm">Save</x-td.button></div>
                                    </div>
                                </details>
                                <div class="flex flex-wrap gap-2 mt-2">
                                    @if ($hasActiveFlag)
                                        <form method="POST" action="{{ route('operator.academic.toggle', [$key, $row->getKey()]) }}">
                                            @csrf
                                            <x-td.button type="submit" size="sm" :variant="$row->is_active ? 'quiet' : 'secondary'">{{ $row->is_active ? 'Deactivate' : 'Reactivate' }}</x-td.button>
                                        </form>
                                    @endif
                                    @if ($key === 'offerings')
                                        <x-td.button :href="route('operator.academic.index', ['resource' => 'topics', 'curriculum_subject_level_id' => $row->getKey()])" size="sm" variant="quiet">Topics</x-td.button>
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="{{ count($config['columns']) + 2 }}">
                            <x-td.state kind="empty" title="Nothing here yet{{ request()->query() ? ' for this filter' : '' }}">Add the first entry above. The reference curriculum list is loaded when the site is set up; ask the administrator if it is missing.</x-td.state>
                        </td></tr>
                    @endforelse
                </tbody>
            </x-td.table>
            @if ($rows->hasPages())<div class="px-5 py-3"><x-td.pagination :paginator="$rows" label="{{ $config['title'] }} pages" /></div>@endif
        </x-td.panel>
    </div>
</x-layouts::operations>
