<?php

namespace App\Http\Middleware;

use App\Domain\Ops\Access\OpsAccess;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gate for platform-operator-only routes. Two checks: the person is a platform operator (users.is_platform_operator),
 * and one of their operator roles carries the permission this route needs (App\Domain\Ops\Access\RoutePermissions,
 * R-47 ops). An operator with no roles keeps full access until roles are assigned (D-1301); an unmapped route is
 * admin only.
 */
class EnsurePlatformOperator
{
    public function __construct(private readonly OpsAccess $access) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        abort_unless($user?->is_platform_operator, 403);
        abort_unless($this->access->allowsRoute($user, $request->route()?->getName()), 403, 'Your operator role does not include this.');

        return $next($request);
    }
}
