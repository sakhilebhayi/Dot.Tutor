<?php

namespace App\Domain\Ops\Support;

use App\Domain\Ops\Access\OpsAccess;
use App\Domain\Ops\Access\RoutePermissions;
use App\View\ShellNavigation;
use Illuminate\Support\Facades\Route;

/**
 * The design system's navigation, narrowed for operators: an operations item whose route the signed-in person's
 * roles cannot open is left out, so the rail never offers a page that answers 403. Bound in place of
 * ShellNavigation by TutorOpsServiceProvider (no change to the ds files). Other shells are untouched.
 */
class OpsShellNavigation extends ShellNavigation
{
    public function for(string $shell, string $step = 'standard'): array
    {
        $sections = parent::for($shell, $step);
        if ($shell !== 'operations') {
            return $sections;
        }

        $user = request()->user();
        $access = app(OpsAccess::class);
        $routeFor = $this->hrefToRoute();

        return collect($sections)
            ->map(function (array $section) use ($user, $access, $routeFor) {
                $section['items'] = array_values(array_filter($section['items'], function (array $item) use ($user, $access, $routeFor) {
                    $name = $routeFor[$item['href']] ?? null;
                    $needs = RoutePermissions::match($name);

                    return $needs === null || $access->canAny($user, $needs);
                }));

                return $section;
            })
            ->filter(fn (array $section) => $section['items'] !== [])
            ->values()
            ->all();
    }

    /** @return array<string, string> href => route name, for the operations items */
    private function hrefToRoute(): array
    {
        $map = [];
        foreach (config('tutor_nav.operations', []) as $section) {
            foreach ($section['items'] ?? [] as $item) {
                $name = collect((array) $item['route'])->first(fn (string $candidate) => Route::has($candidate));
                if ($name !== null) {
                    $map[route($name)] = $name;
                }
            }
        }

        return $map;
    }
}
