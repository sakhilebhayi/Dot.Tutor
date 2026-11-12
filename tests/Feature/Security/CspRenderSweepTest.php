<?php

namespace Tests\Feature\Security;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * R-47 security D-1514: the enforced Content-Security-Policy against what the pages really contain. Every GET page
 * that renders HTML for the Demo personas (learners, a child, a guardian, tutors, operators) is read: every inline
 * script carries this response's nonce, no element has an inline event handler or a javascript: link, nothing
 * loads from another host, and a page that uses Alpine or Livewire is the one that gets 'unsafe-eval'.
 * A browser pass over the main screens (docs/R47_SECURITY.md section 4) checks what this cannot: runtime behaviour.
 */
class CspRenderSweepTest extends TestCase
{
    use RefreshDatabase;

    private const PERSONAS = [
        'demo.learner@example.test', 'demo.grade3@example.test', 'demo.guardian@example.test', 'demo.ops.guardian@example.test',
        'demo.tutor@example.test', 'demo.workspace.tutor@example.test', 'demo.classroom.tutor@example.test',
        'demo.roomtutor@example.test', 'demo.teen@example.test', 'demo.ops.admin@example.test', 'demo.moderator@example.test',
    ];

    public function test_every_rendered_page_fits_the_enforced_policy(): void
    {
        Notification::fake();
        foreach (['learning', 'safety', 'marketplace', 'classroom', 'lesson_resources'] as $disk) {
            Storage::fake($disk);
        }
        config(['security.csp_report_only' => false]);

        foreach (['SubjectCatalogueSeeder', 'AcademicReferenceSeeder', 'DemoStudentSeeder', 'DemoTutorWorkspaceSeeder',
            'DemoLearningSeeder', 'DemoCommunitySeeder', 'DemoClassroomSeeder', 'DemoOpsSeeder'] as $seeder) {
            $this->seed('Database\\Seeders\\'.$seeder);
        }

        $problems = [];
        $pages = 0;
        $routes = collect(Route::getRoutes()->getRoutes())
            ->filter(fn (RoutingRoute $r) => in_array('GET', $r->methods(), true) && ! str_starts_with($r->uri(), 'livewire')
                && ! str_starts_with($r->uri(), '_boost')
                // The framework's health page: its CDN styling is simply blocked by the enforced policy (monitors read
                // the status code, nothing loads from elsewhere).
                && $r->uri() !== 'up');

        foreach (array_merge([null], self::PERSONAS) as $email) {
            $user = $email ? User::query()->where('email', $email)->first() : null;
            if ($email && ! $user) {
                $problems[] = "persona missing: $email";

                continue;
            }

            foreach ($routes as $route) {
                $uri = $this->uri($route);
                if ($uri === null) {
                    continue;
                }

                $this->app['auth']->forgetGuards();
                $response = $user ? $this->actingAs($user)->get($uri) : $this->get($uri);

                if ($response->getStatusCode() !== 200 || ! str_contains((string) $response->headers->get('Content-Type'), 'text/html')) {
                    continue;
                }

                $pages++;
                foreach ($this->check((string) $response->getContent(), (string) $response->headers->get('Content-Security-Policy')) as $problem) {
                    $problems[] = ($email ?? 'guest')." /$uri: $problem";
                }
            }
        }

        $this->assertGreaterThan(150, $pages, 'the sweep must render the app\'s pages');
        $this->assertSame([], array_values(array_unique($problems)), "Pages that break the enforced policy:\n".implode("\n", array_unique($problems)));
    }

    /** @return list<string> */
    private function check(string $html, string $policy): array
    {
        $problems = [];

        if (! preg_match("/'nonce-([A-Za-z0-9]+)'/", $policy, $m)) {
            return ['no nonce in the policy'];
        }
        $nonce = $m[1];

        preg_match_all('/<script\b([^>]*)>/i', $html, $scripts);
        foreach ($scripts[1] as $attributes) {
            if (preg_match('/\bsrc="([^"]+)"/', $attributes, $src)) {
                if (preg_match('#^(https?:)?//#', $src[1]) && ! str_starts_with($src[1], rtrim((string) config('app.url'), '/'))) {
                    $problems[] = "script from another host: {$src[1]}";
                }
            } elseif (! str_contains($attributes, 'nonce="'.$nonce.'"') && ! preg_match('/type="application\/(ld\+)?json"/', $attributes)) {
                $problems[] = 'inline script without the nonce';
            }
        }

        if (preg_match_all('/<[a-z][^>]*\s(on[a-z]+)\s*=\s*["\']/i', $html, $handlers)) {
            $problems[] = 'inline event handler: '.implode(', ', array_unique($handlers[1]));
        }

        if (preg_match('/\s(href|src|action)\s*=\s*["\']\s*javascript:/i', $html)) {
            $problems[] = 'javascript: link';
        }

        if (preg_match_all('/<(?:link|img|iframe|source|video|audio)\b[^>]*\s(?:src|href)="((?:https?:)?\/\/[^"]+)"/i', $html, $external)) {
            foreach ($external[1] as $url) {
                if (! str_starts_with($url, rtrim((string) config('app.url'), '/'))) {
                    $problems[] = "resource from another host: $url";
                }
            }
        }

        $usesAlpine = (bool) preg_match('/\s(x-data|x-on:|x-show|x-bind|wire:[a-z]+)/', $html);
        if ($usesAlpine && ! str_contains($policy, "'unsafe-eval'")) {
            $problems[] = 'uses Alpine or Livewire without unsafe-eval';
        }

        return $problems;
    }

    private function uri(RoutingRoute $route): ?string
    {
        $types = [];
        foreach ($route->signatureParameters(['subClass' => Model::class]) as $parameter) {
            $types[$parameter->getName()] = $parameter->getType()?->getName();
        }

        $uri = $route->uri();
        foreach ($route->parameterNames() as $name) {
            $class = $types[$name] ?? null;
            $value = $class && class_exists($class) ? $class::query()->orderBy((new $class)->getKeyName())->first()?->getRouteKey() : null;

            if ($value === null) {
                return null;
            }

            $uri = preg_replace('/\{'.$name.'\??\}/', (string) $value, $uri);
        }

        return $uri;
    }
}
