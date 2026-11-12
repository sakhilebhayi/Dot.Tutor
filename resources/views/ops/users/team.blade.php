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
                <x-td.state kind="empty" title="No operators yet">The first administrator is granted on the server when the site is set up (the deployment guide has the step). After that, administrators give roles here.</x-td.state>
            @else
                {{-- One line per operator with their roles in words; the role form opens behind "Change roles" (D-2212),
                     so the page no longer repeats seven checkboxes for every person. --}}
                <ul class="ops-work">
                    @foreach ($operators as $o)
                        @php($u = $o['user'])
                        <li>
                            <div class="grid min-w-0 gap-1">
                                <p class="ops-work__title"><a href="{{ route('ops.users.show', $u) }}">{{ $u->name }}</a> <span class="block ops-muted text-sm [overflow-wrap:anywhere]" style="font-weight:400">{{ $u->email }}</span></p>
                                <p class="ops-work__meta">
                                    @if ($o['roles'] === [])
                                        @include('ops.partials.tag', ['text' => 'Full access, no role yet', 'tone' => 'warning'])
                                    @else
                                        {{ collect($o['roles'])->map(fn ($r) => \App\Domain\Ops\Access\OpsRole::tryFrom($r)?->label() ?? $r)->join(', ') }}
                                    @endif
                                </p>
                            </div>
                            <details class="ops-team__change">
                                <summary class="td-btn td-btn--sm">Change roles<span class="td-sr"> for {{ $u->name }}</span></summary>
                                <div class="ops-team__sheet grid gap-4">
                                    <form method="POST" action="{{ route('ops.users.team.update', $u) }}" class="grid gap-3" aria-label="Roles for {{ $u->name }}">
                                        @csrf
                                        <fieldset class="grid gap-2 border-0 p-0 m-0">
                                            <legend class="td-label mb-1">Roles for {{ $u->name }}</legend>
                                            @foreach ($roles as $role)
                                                <x-td.check name="roles[]" id="role-{{ $u->id }}-{{ $role->value }}" :value="$role->value" :checked="in_array($role->value, $o['roles'], true)">{{ $role->label() }}<span class="block text-sm ops-muted">{{ $role->summary() }}</span></x-td.check>
                                            @endforeach
                                        </fieldset>
                                        <div><x-td.button type="submit" size="sm" variant="primary">Save roles</x-td.button></div>
                                    </form>
                                    @if (! $u->is(request()->user()))
                                        <form method="POST" action="{{ route('ops.users.team.remove', $u) }}" data-confirm="Remove operator access for {{ $u->name }}? Their account stays; they lose every operator screen.">
                                            @csrf
                                            <x-td.button type="submit" size="sm" variant="quiet" icon="x">Remove operator access</x-td.button>
                                        </form>
                                    @endif
                                </div>
                            </details>
                        </li>
                    @endforeach
                </ul>
            @endif
        </x-td.panel>

        <x-td.panel title="Add an operator">
            <form method="POST" action="{{ route('ops.users.team.store') }}" class="grid gap-4">
                @csrf
                <x-td.field label="Their sign-in email" for="team-email" hint="They must have signed in through InfoDot once."><x-td.input name="email" id="team-email" type="email" required hint /></x-td.field>
                <fieldset class="grid gap-2 border-0 p-0 m-0 md:grid-cols-2">
                    <legend class="td-label mb-2">Roles</legend>
                    @foreach ($roles as $role)
                        <x-td.check name="roles[]" id="new-role-{{ $role->value }}" :value="$role->value">{{ $role->label() }}<span class="block text-sm ops-muted">{{ $role->summary() }}</span></x-td.check>
                    @endforeach
                </fieldset>
                @error('roles')<p class="td-error"><x-td.icon name="circle-alert" />{{ $message }}</p>@enderror
                <div><x-td.button type="submit" variant="primary">Add operator</x-td.button></div>
            </form>
        </x-td.panel>

        <x-td.panel title="What each role reaches" flush>
            <details class="ops-team__matrix">
            <summary class="cursor-pointer px-5 py-3 font-semibold">Show every permission by role</summary>
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
            </details>
        </x-td.panel>
    </div>
</x-layouts::operations>
