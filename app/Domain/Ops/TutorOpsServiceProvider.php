<?php

namespace App\Domain\Ops;

use App\Domain\Ops\Access\OpsAccess;
use App\Domain\Ops\Access\RoutePermissions;
use App\Domain\Ops\Console\GrantOperatorRole;
use App\Domain\Ops\Http\Middleware\OperatorRouteGuard;
use App\Domain\Ops\Support\OpsShellNavigation;
use App\Models\User;
use App\View\ShellNavigation;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

/**
 * R-47 operations command centre and guardian views (ops agent, docs/R47_UI_OPS.md). Operator roles on top of
 * users.is_platform_operator, the route permission matrix and its two enforcement points, the role answer for the
 * other domains' "any operator" gates, the rail narrowed by role, and the design-system restyle of the academic
 * operator screens and the guardian acceptance page (view overrides; the owners' controllers are unchanged).
 * Everything is registered here, never in route files (route:cache).
 */
class TutorOpsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->scoped(OpsAccess::class);
        $this->app->singleton(ShellNavigation::class, OpsShellNavigation::class);
    }

    public function boot(): void
    {
        $this->app->make(Router::class)->pushMiddlewareToGroup('web', OperatorRouteGuard::class);

        // For someone who holds roles, the domains' "any platform operator" gates answer from the role. A legacy
        // full-access operator (no roles yet) and everyone else fall through to the domain's own rule.
        Gate::before(function (User $user, string $ability) {
            if (! isset(RoutePermissions::GATES[$ability]) || ! $user->is_platform_operator) {
                return null;
            }

            $access = app(OpsAccess::class);
            if ($access->hasLegacyFullAccess($user)) {
                return null;
            }

            // Roles are authoritative for role holders: they replace the interim MONEY_FINANCE_OPERATOR_EMAILS narrowing (D-1303).
            return $access->canAny($user, RoutePermissions::GATES[$ability]);
        });

        // Restyled owner screens on the design system: same data, same form fields and routes, new shell.
        View::prependNamespace('academic', resource_path('views/ops/overrides/academic'));
        View::prependNamespace('safety', resource_path('views/guardian/overrides/safety'));

        if ($this->app->runningInConsole()) {
            $this->commands([GrantOperatorRole::class]);
        }
    }
}
