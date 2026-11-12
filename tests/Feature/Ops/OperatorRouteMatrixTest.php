<?php

namespace Tests\Feature\Ops;

use App\Domain\Ops\Access\OpsAccess;
use App\Domain\Ops\Access\OpsPermission;
use App\Domain\Ops\Access\OpsRole;
use App\Domain\Ops\Access\RoleAdministration;
use App\Domain\Ops\Access\RoutePermissions;
use App\Models\User;
use App\View\ShellNavigation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The operator route permission matrix (R-47 ops, adapted from Dot.Ehail's RouteAuthorizationMapTest).
 * A new operator route in any domain that nobody classified fails here; so does a stale entry, and so does any
 * role reaching a page outside its permissions.
 */
class OperatorRouteMatrixTest extends TestCase
{
    use RefreshDatabase;

    /** Controllers whose every route is operator-only, whichever way the domain guarded it. */
    private const OPERATOR_ACTIONS = '/\\\\Operator\\\\|OperatorController|OperatorLogController|ModerationController|FinanceOpsController|TutorProfileReviewController|SubjectController|\\\\Ops\\\\Http\\\\Controllers\\\\(?!GuardianController)/';

    /** @return list<RoutingRoute> */
    private function operatorRoutes(): array
    {
        return collect(Route::getRoutes()->getRoutes())->filter(function (RoutingRoute $route) {
            $middleware = $route->gatherMiddleware();
            $gated = collect($middleware)->contains(fn ($m) => is_string($m) && (
                $m === 'operator' || str_contains($m, 'EnsurePlatformOperator')
                || collect(array_keys(RoutePermissions::GATES))->contains(fn ($gate) => $m === 'can:'.$gate)));

            return $gated || preg_match(self::OPERATOR_ACTIONS, (string) $route->getActionName()) === 1;
        })->values()->all();
    }

    public function test_every_operator_route_is_named_and_classified(): void
    {
        $routes = $this->operatorRoutes();
        $this->assertGreaterThan(100, count($routes), 'the scan must find the domains\' operator routes');

        $unclassified = collect($routes)
            ->filter(fn (RoutingRoute $r) => ! RoutePermissions::isMapped($r->getName()))
            ->map(fn (RoutingRoute $r) => ($r->getName() ?? '(unnamed)').' '.implode('|', $r->methods()).' '.$r->uri())
            ->values()->all();

        $this->assertSame([], $unclassified, "Classify these operator routes in App\\Domain\\Ops\\Access\\RoutePermissions::MAP:\n".implode("\n", $unclassified));
    }

    public function test_the_scan_covers_every_domain_named_in_the_brief(): void
    {
        $names = collect($this->operatorRoutes())->map->getName()->filter();

        foreach (['ai.operator.log', 'rooms.moderation', 'money.api.ops.refunds.store', 'safety.operator.queue', 'learning.library.moderation',
            'operator.academic.index', 'marketplace.api.operator.qualifications', 'operator.tutor-profiles.index', 'ops.home', 'ops.payments.refund'] as $expected) {
            $this->assertContains($expected, $names, "{$expected} must be found by the operator route scan");
        }
    }

    public function test_every_map_entry_matches_a_registered_route(): void
    {
        $names = collect(Route::getRoutes()->getRoutes())->map->getName()->filter()->values();

        $stale = collect(array_keys(RoutePermissions::MAP))
            ->filter(fn (string $pattern) => ! $names->contains(fn ($n) => $n === $pattern || (str_contains($pattern, '*') && Str::is($pattern, $n))))
            ->values()->all();

        $this->assertSame([], $stale, 'Map entries that match no route: '.implode(', ', $stale));

        foreach (RoutePermissions::MAP as $pattern => $permissions) {
            foreach ($permissions as $permission) {
                $this->assertContains($permission, OpsPermission::all(), "{$pattern} names an unknown permission");
            }
        }
    }

    public function test_an_unmapped_operator_route_is_admin_only(): void
    {
        $this->assertSame([OpsPermission::UNMAPPED], RoutePermissions::for('operator.something.new'));
        $finance = $this->withRoles(OpsRole::Finance);
        $admin = $this->withRoles(OpsRole::Admin);

        $this->assertFalse(app(OpsAccess::class)->allowsRoute($finance, 'operator.something.new'));
        $this->assertTrue(app(OpsAccess::class)->allowsRoute($admin, 'operator.something.new'));
    }

    public function test_each_role_reaches_exactly_the_operator_pages_its_permissions_allow(): void
    {
        $pages = collect($this->operatorRoutes())
            ->filter(fn (RoutingRoute $r) => in_array('GET', $r->methods(), true) && $r->getName() !== null && $r->parameterNames() === []
                && ! str_contains($r->getName(), 'export'))
            ->values();
        $this->assertGreaterThan(25, $pages->count());

        foreach (OpsRole::cases() as $role) {
            $user = $this->withRoles($role);

            foreach ($pages as $route) {
                $allowed = array_intersect(RoutePermissions::for($route->getName()), $role->permissions()) !== [];
                $status = $this->actingAs($user)->get('/'.ltrim($route->uri(), '/'))->getStatusCode();

                if ($allowed) {
                    $this->assertNotSame(403, $status, "{$role->value} should reach {$route->getName()}");
                    $this->assertLessThan(500, $status, "{$route->getName()} failed for {$role->value}");
                } else {
                    $this->assertSame(403, $status, "{$role->value} must not reach {$route->getName()}");
                }
            }
        }
    }

    public function test_people_who_are_not_operators_reach_no_operator_page(): void
    {
        $learner = User::factory()->create();

        collect($this->operatorRoutes())
            ->filter(fn (RoutingRoute $r) => in_array('GET', $r->methods(), true) && $r->parameterNames() === [])
            ->each(fn (RoutingRoute $r) => $this->actingAs($learner)->get('/'.ltrim($r->uri(), '/'))->assertForbidden());
    }

    public function test_an_operator_without_roles_keeps_full_access_until_roles_are_assigned(): void
    {
        $legacy = User::factory()->create(['is_platform_operator' => true]);
        $legacy->forceFill(['is_platform_operator' => true])->save();

        $this->assertTrue(app(OpsAccess::class)->hasLegacyFullAccess($legacy));
        $this->actingAs($legacy)->get(route('ops.payments'))->assertOk();
        $this->actingAs($legacy)->get(route('ops.safeguarding'))->assertOk();
        $this->actingAs($legacy)->get(route('ops.users.team'))->assertOk();
    }

    public function test_domain_gates_answer_from_the_role_for_role_holders(): void
    {
        $finance = $this->withRoles(OpsRole::Finance);
        $safeguarding = $this->withRoles(OpsRole::SafeguardingOfficer);
        $moderator = $this->withRoles(OpsRole::ContentModerator);

        $this->assertTrue(Gate::forUser($finance)->allows('money.finance'));
        $this->assertFalse(Gate::forUser($this->withRoles(OpsRole::AcademicEditor))->allows('safety.operate'));
        $this->actingAs($finance)->get(route('safety.operator.queue'))->assertForbidden();
        $this->assertFalse(Gate::forUser($finance)->allows('community.moderate'));
        $this->assertTrue(Gate::forUser($safeguarding)->allows('safety.operate'));
        $this->assertTrue(Gate::forUser($safeguarding)->allows('ai.review-log'));
        $this->assertFalse(Gate::forUser($safeguarding)->allows('money.finance'));
        $this->assertTrue(Gate::forUser($moderator)->allows('community.moderate'));
        $this->assertFalse(Gate::forUser($moderator)->allows('ai.review-log'));

        // Roles replace the interim email narrowing of the finance gate.
        config(['money.finance_operators' => ['someone-else@example.com']]);
        $this->assertTrue(Gate::forUser($finance)->allows('money.finance'));
    }

    public function test_a_role_cannot_use_another_roles_actions(): void
    {
        $other = User::factory()->create();
        $cases = [
            [OpsRole::ContentModerator, 'post', route('ops.users.restrict', $other), ['action' => 'suspend', 'reason' => 'x']],
            [OpsRole::Finance, 'post', route('ops.safeguarding.incidents.store'), ['title' => 'x', 'severity' => 'high']],
            [OpsRole::SupportAgent, 'post', route('ops.payouts.draft'), []],
            [OpsRole::VerificationReviewer, 'post', route('ops.users.team.update', $other), ['roles' => ['admin']]],
            [OpsRole::AcademicEditor, 'post', route('ops.moderation.posts.decide', 1), ['decision' => 'release']],
            [OpsRole::SafeguardingOfficer, 'post', route('ops.payments.issues.resolve', 1), ['note' => 'x']],
            [OpsRole::ContentModerator, 'post', route('safety.operator.users.act', $other), ['action' => 'warn', 'reason' => 'x']],
            [OpsRole::Finance, 'post', route('rooms.moderation.decide', 1), ['decision' => 'remove']],
            [OpsRole::SupportAgent, 'post', route('money.api.ops.batches.store'), []],
            [OpsRole::Finance, 'post', route('ops.support.reply', 1), ['body' => 'x']],
        ];

        foreach ($cases as [$role, $method, $url, $data]) {
            $this->assertSame(403, $this->actingAs($this->withRoles($role))->{$method}($url, $data)->getStatusCode(), "{$role->value} {$url}");
        }

        $this->assertFalse((bool) $other->fresh()->is_platform_operator);
    }

    public function test_the_operations_rail_shows_only_what_the_role_can_open(): void
    {
        $moderator = $this->withRoles(OpsRole::ContentModerator);
        $this->actingAs($moderator)->get(route('ops.moderation'))->assertOk();

        $labels = collect(app(ShellNavigation::class)->for('operations', 'dense'))->flatMap(fn ($s) => $s['items'])->pluck('label')->all();

        $this->assertContains('Content moderation', $labels);
        $this->assertContains('Overview', $labels);
        $this->assertNotContains('Orders and payments', $labels);
        $this->assertNotContains('Reports and incidents', $labels);
        $this->assertContains('Users and roles', $labels, 'moderators look people up');
    }

    private function withRoles(OpsRole ...$roles): User
    {
        $admin = User::factory()->create();
        $user = User::factory()->create();
        app(RoleAdministration::class)->setRoles($admin, $user, array_map(fn (OpsRole $r) => $r->value, $roles));

        return $user->fresh();
    }
}
