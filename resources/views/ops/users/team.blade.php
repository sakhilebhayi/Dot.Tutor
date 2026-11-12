<x-layouts::operations title="Operators and roles">
    <x-td.page-head title="Operators and roles" lede="Who runs Dot.Tutor and what each role reaches. Every change is written to the audit log with the roles before and after." />
    @include('ops.partials.tabs', ['label' => 'People', 'items' => [['label' => 'People', 'route' => 'ops.users'], ['label' => 'Operators and roles', 'route' => 'ops.users.team']]])

    <div class="grid gap-6">
        @php($legacy = $operators->filter(fn ($o) => $o['roles'] === []))
        @if ($legacy->isNotEmpty())
            <x-td.notice tone="warning" title="{{ $legacy->count() === 1 ? 'One operator has' : $legacy->count().' operators have' }} full access without a role">
                Operators from before roles existed keep full access until you give them roles: {{ $legacy->map(fn ($o) => $o['user']->name)->join(', ') }}.
            </x-td.notice>
        @endif

        <x-td.panel title="Operators" flush>
            @if ($operators->isEmpty())
                <x-td.state kind="empty" title="No operators yet">Grant the first administrator on the server with php artisan ops:grant followed by their email.</x-td.state>
            @else
                <ul class="ops-work">
                    @foreach ($operators as $o)
                        @php($u = $o['user'])
                        <li>
                            <form method="POST" action="{{ route('ops.users.team.update', $u) }}" class="grid gap-3" aria-label="Roles for {{ $u->name }}">
                                @csrf
                                <p class="ops-work__title"><a href="{{ route('ops.users.show', $u) }}">{{ $u->name }}</a> <span class="ops-muted text-sm" style="font-weight:400">{{ $u->email }}</span></p>
                                <fieldset class="flex flex-wrap gap-x-5 gap-y-2 border-0 p-0 m-0">
                                    <legend class="td-sr">Roles for {{ $u->name }}</legend>
                                    @foreach ($roles as $role)
                                        <x-td.check name="roles[]" id="role-{{ $u->id }}-{{ $role->value }}" :value="$role->value" :checked="in_array($role->value, $o['roles'], true)">{{ $role->label() }}</x-td.check>
                                    @endforeach
                                </fieldset>
                                <div class="flex flex-wrap gap-2">
                                    <x-td.button type="submit" size="sm">Save roles</x-td.button>
                                    @if (! $u->is(request()->user()))
                                        <x-td.button type="submit" size="sm" variant="quiet" formaction="{{ route('ops.users.team.remove', $u) }}">Remove operator access</x-td.button>
                                    @endif
                                </div>
                            </form>
                        </li>
                    @endforeach
                </ul>
            @endif
        </x-td.panel>

        <x-td.panel title="Add an operator">
            <form method="POST" action="{{ route('ops.users.team.store') }}" class="grid gap-4">
                @csrf
                <x-td.field label="Their sign-in email" for="team-email" hint="They must have signed in through InfoDot once."><x-td.input name="email" id="team-email" type="email" required hint /></x-td.field>
                <fieldset class="flex flex-wrap gap-x-5 gap-y-2 border-0 p-0 m-0">
                    <legend class="td-label mb-2">Roles</legend>
                    @foreach ($roles as $role)
                        <x-td.check name="roles[]" id="new-role-{{ $role->value }}" :value="$role->value">{{ $role->label() }}</x-td.check>
                    @endforeach
                </fieldset>
                @error('roles')<p class="td-error"><x-td.icon name="circle-alert" />{{ $message }}</p>@enderror
                <div><x-td.button type="submit" variant="primary">Add operator</x-td.button></div>
            </form>
        </x-td.panel>

        <x-td.panel title="What each role reaches" flush>
            <x-td.table caption="Permissions by role" caption-hidden dense>
                <thead><tr><th scope="col">Permission</th>@foreach ($roles as $role)<th scope="col">{{ $role->label() }}</th>@endforeach</tr></thead>
                <tbody>
                    @foreach ($permissions as $permission => $description)
                        <tr>
                            <th scope="row" class="text-sm" style="font-weight:400">{{ $description }}</th>
                            @foreach ($roles as $role)
                                <td>@if (in_array($permission, $role->permissions(), true))<x-td.icon name="check" label="Yes" />@else<span class="td-sr">No</span>@endif</td>
                            @endforeach
                        </tr>
                    @endforeach
                </tbody>
            </x-td.table>
        </x-td.panel>
    </div>
</x-layouts::operations>
