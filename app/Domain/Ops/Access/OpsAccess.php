<?php

namespace App\Domain\Ops\Access;

use App\Models\User;

/**
 * Who may do what in operations. Roles sit on top of users.is_platform_operator: a person who is not a platform
 * operator holds no operations permission whatever rows exist. A platform operator with no role rows keeps the
 * full access every operator had before roles existed (legacy full access, D-1301) until an administrator assigns
 * roles; the team page lists such people so the gap is visible. Answers are memoised per user for the request.
 */
class OpsAccess
{
    /** @var array<int, list<string>> */
    private array $roleCache = [];

    public function isOperator(?User $user): bool
    {
        return $user !== null && (bool) $user->is_platform_operator;
    }

    /** @return list<string> role values held by the user (empty for a legacy operator) */
    public function roles(User $user): array
    {
        return $this->roleCache[$user->id] ??= OpsRoleAssignment::query()
            ->where('user_id', $user->id)
            ->orderBy('role')
            ->pluck('role')
            ->filter(fn (string $role) => OpsRole::tryFrom($role) !== null)
            ->values()
            ->all();
    }

    public function hasLegacyFullAccess(User $user): bool
    {
        return $this->isOperator($user) && $this->roles($user) === [];
    }

    public function isAdmin(?User $user): bool
    {
        if (! $this->isOperator($user)) {
            return false;
        }

        return $this->hasLegacyFullAccess($user) || in_array(OpsRole::Admin->value, $this->roles($user), true);
    }

    /** @return list<string> */
    public function permissions(?User $user): array
    {
        if (! $this->isOperator($user)) {
            return [];
        }

        if ($this->isAdmin($user)) {
            return OpsPermission::all();
        }

        $permissions = [];
        foreach ($this->roles($user) as $role) {
            array_push($permissions, ...OpsRole::from($role)->permissions());
        }

        return array_values(array_unique($permissions));
    }

    public function can(?User $user, string $permission): bool
    {
        if (! $this->isOperator($user)) {
            return false;
        }

        if ($permission === OpsPermission::UNMAPPED) {
            return $this->isAdmin($user);
        }

        return in_array($permission, $this->permissions($user), true);
    }

    /** @param list<string> $permissions any of these */
    public function canAny(?User $user, array $permissions): bool
    {
        foreach ($permissions as $permission) {
            if ($this->can($user, $permission)) {
                return true;
            }
        }

        return false;
    }

    /** Whether the user may use an operator route (RoutePermissions decides the permission). */
    public function allowsRoute(?User $user, ?string $routeName): bool
    {
        return $this->canAny($user, RoutePermissions::for($routeName));
    }

    public function forget(User $user): void
    {
        unset($this->roleCache[$user->id]);
    }
}
