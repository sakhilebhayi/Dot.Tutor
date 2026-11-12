<?php

namespace App\Domain\Ops\Http\Controllers;

use App\Domain\Ops\Access\OpsPermission;
use App\Domain\Ops\Access\OpsRole;
use App\Domain\Ops\Access\RoleAdministration;
use App\Domain\Ops\Access\RoutePermissions;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Team management (administrators only): who operates Dot.Tutor, with which roles, and what each role reaches. */
class TeamController extends OpsController
{
    public function index(): View
    {
        $operators = User::query()->where('is_platform_operator', true)->orderBy('name')->get();

        return view('ops.users.team', [
            'operators' => $operators->map(fn (User $u) => ['user' => $u, 'roles' => $this->access()->roles($u)]),
            'roles' => OpsRole::cases(),
            'permissions' => OpsPermission::DESCRIPTIONS,
        ]);
    }

    public function store(Request $request, RoleAdministration $admin): RedirectResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'roles' => ['required', 'array', 'min:1'],
            'roles.*' => [Rule::enum(OpsRole::class)],
        ]);

        $user = User::query()->whereRaw('lower(email) = ?', [mb_strtolower($data['email'])])->first();
        if ($user === null) {
            return back(303)->withErrors(['email' => 'Nobody with that email has signed in to Dot.Tutor yet. They sign in once through InfoDot, then you can add them.'])->withInput();
        }

        $admin->setRoles($request->user(), $user, $data['roles']);

        return $this->done("{$user->name} is now an operator.");
    }

    public function update(Request $request, User $user, RoleAdministration $admin): RedirectResponse
    {
        $data = $request->validate(['roles' => ['required', 'array', 'min:1'], 'roles.*' => [Rule::enum(OpsRole::class)]]);
        $admin->setRoles($request->user(), $user, $data['roles']);

        return $this->done('Roles saved.');
    }

    public function remove(Request $request, User $user, RoleAdministration $admin): RedirectResponse
    {
        abort_unless($user->is_platform_operator, 404);
        $admin->removeOperator($request->user(), $user);

        return $this->done("{$user->name} is no longer an operator.");
    }

    /** @return list<array{route: string, roles: list<string>}> for the docs and the team page */
    public static function matrix(): array
    {
        return collect(RoutePermissions::MAP)->map(fn ($p, string $pattern) => ['route' => $pattern, 'roles' => RoutePermissions::rolesFor(str_replace('*', 'x', $pattern))])->values()->all();
    }
}
