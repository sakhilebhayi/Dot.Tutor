<?php

namespace App\View;

use Illuminate\Support\Facades\Route;

/**
 * Resolves a shell's navigation (config/tutor_nav.php) for the current request.
 *
 * An item names one or more candidate routes; the first that exists is used, and an item with none is left
 * out, so a shell never shows a link to a screen that has not been built. An item lights up (aria-current)
 * when the current route matches one of its patterns. Items can be limited to steps (young, standard, dense).
 */
class ShellNavigation
{
    /**
     * @return list<array{label: ?string, items: list<array{label: string, href: string, icon: string, current: bool, bottom: bool}>}>
     */
    public function for(string $shell, string $step = 'standard'): array
    {
        $sections = [];

        foreach (config("tutor_nav.{$shell}", []) as $section) {
            $items = [];

            foreach ($section['items'] ?? [] as $item) {
                if (isset($item['steps']) && ! in_array($step, $item['steps'], true)) {
                    continue;
                }

                $route = collect((array) $item['route'])->first(fn (string $name) => Route::has($name));
                if ($route === null) {
                    continue;
                }

                $items[] = [
                    'label' => $step === 'young' && isset($item['young_label']) ? $item['young_label'] : $item['label'],
                    'href' => route($route),
                    'icon' => $item['icon'] ?? 'circle-dashed',
                    'current' => $this->isCurrent($item['match'] ?? $route),
                    'bottom' => (bool) ($item['bottom'] ?? false),
                ];
            }

            if ($items !== []) {
                $sections[] = ['label' => $section['label'] ?? null, 'items' => $items];
            }
        }

        return $sections;
    }

    /**
     * The items a phone shows in the learner's bottom bar (at most four).
     *
     * @return list<array{label: string, href: string, icon: string, current: bool, bottom: bool}>
     */
    public function bottomBar(string $shell, string $step = 'standard'): array
    {
        return collect($this->for($shell, $step))
            ->flatMap(fn (array $section) => $section['items'])
            ->filter(fn (array $item) => $item['bottom'])
            ->take(4)
            ->values()
            ->all();
    }

    /** @param  string|list<string>  $patterns */
    private function isCurrent(string|array $patterns): bool
    {
        $request = request();

        foreach ((array) $patterns as $pattern) {
            if ($request->routeIs($pattern)) {
                return true;
            }
        }

        return false;
    }
}
