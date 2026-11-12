@php use App\Domain\Ops\Http\Controllers\UsersController; @endphp
<x-layouts::operations title="Users">
    <x-td.page-head title="Users" lede="Learners, tutors, guardians and operators. Minors are flagged from a verified or declared date of birth or an active guardian link." />
    @include('ops.partials.tabs', ['label' => 'People', 'items' => [['label' => 'People', 'route' => 'ops.users'], ['label' => 'Operators and roles', 'route' => 'ops.users.team']]])

    <form method="GET" action="{{ route('ops.users') }}" class="ops-act mb-5" role="search">
        <x-td.field label="Name or email" for="q"><x-td.input name="q" id="q" type="search" :value="$filters['q'] ?? ''" /></x-td.field>
        <x-td.field label="Role" for="role"><x-td.select name="role" id="role" :options="UsersController::ROLES" :value="$filters['role'] ?? ''" placeholder="Everyone" /></x-td.field>
        <x-td.field label="Flag" for="flag"><x-td.select name="flag" id="flag" :options="UsersController::FLAGS" :value="$filters['flag'] ?? ''" placeholder="Any" /></x-td.field>
        <x-td.button type="submit" icon="search">Find</x-td.button>
    </form>

    <x-td.panel flush>
        @if ($rows->isEmpty())
            <x-td.state kind="empty" title="{{ $filters === [] ? 'Nobody has signed in yet' : 'Nobody matches these filters' }}">People appear after their first InfoDot sign-in.</x-td.state>
        @else
            <x-td.table caption="People, newest first" caption-hidden>
                <thead><tr><th scope="col">Name</th><th scope="col">Roles</th><th scope="col">Age</th><th scope="col">Standing</th><th scope="col">Joined</th></tr></thead>
                <tbody>
                    @foreach ($rows as $row)
                        @php $u = $row['user']; $a = $row['account']; @endphp
                        <tr>
                            <td><a href="{{ route('ops.users.show', $u) }}">{{ $u->name }}</a></td>
                            <td class="text-sm">
                                {{ collect([
                                    $row['tutor'] ? 'Tutor ('.$row['tutor'].')' : null,
                                    $row['guardian'] ? 'Guardian' : null,
                                    $u->is_platform_operator ? 'Operator' : null,
                                ])->filter()->join(', ') ?: 'Learner' }}
                            </td>
                            <td>
                                @if ($a['is_minor'])
                                    @include('ops.partials.tag', ['text' => \App\Domain\Safety\Enums\AgeBand::from($a['age_band'])->label(), 'tone' => 'info', 'icon' => 'hand-heart'])
                                @else
                                    <span class="ops-muted text-sm">{{ $a['age_known'] ? '18 or older' : 'Not known' }}</span>
                                @endif
                            </td>
                            <td>
                                @include('ops.partials.tag', match ($a['status']) {
                                    'suspended' => ['text' => 'Suspended', 'tone' => 'danger'],
                                    'limited' => ['text' => 'Limited, no guardian consent', 'tone' => 'warning'],
                                    default => ['text' => 'Active', 'tone' => 'success'],
                                })
                            </td>
                            <td class="text-sm ops-num">{{ $u->created_at?->timezone(config('tutor.timezone'))->format('j M Y') }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </x-td.table>
            @if ($users->hasPages())<div class="px-5 py-3"><x-td.pagination :paginator="$users" label="People pages" /></div>@endif
        @endif
    </x-td.panel>
</x-layouts::operations>
