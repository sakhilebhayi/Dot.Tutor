<?php

namespace Tests\Feature;

use App\View\ShellNavigation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\Concerns\AssertsResponsiveShell;
use Tests\Concerns\BuildsMarketplace;
use Tests\TestCase;

/** The R-47 design system: the kit renders with its accessibility hooks, shells resolve navigation honestly, and no page loads a third-party host. */
class DesignSystemKitTest extends TestCase
{
    use AssertsResponsiveShell, BuildsMarketplace, RefreshDatabase;

    private const THIRD_PARTY_HOSTS = ['fonts.googleapis.com', 'fonts.gstatic.com', 'unpkg.com', 'cdn.tailwindcss.com', 'Material+Symbols', 'images.unsplash.com'];

    protected function setUp(): void
    {
        parent::setUp();

        Route::middleware('web')->get('/_test-only-ds-kit/{step?}', fn (string $step = 'standard') => view('layouts.kit', ['step' => $step]));
    }

    public function test_the_kit_renders_every_component_inside_the_learner_shell(): void
    {
        $response = $this->actingAs($this->makeUser('Kit Learner'))->get('/_test-only-ds-kit');
        $xpath = $this->assertResponsiveShell($response);
        $html = $response->getContent();

        if (getenv('DS_DUMP_KIT')) {
            @mkdir(public_path('__ds'));
            file_put_contents(public_path('__ds/kit.html'), $html);
        }

        $this->assertSame(1, $xpath->query('//body[contains(@class, "td-app--learner")]')->length);
        $this->assertSame(1, $xpath->query('//button[contains(@class, "td-btn--primary")][@aria-busy="true"][@disabled]')->length, 'loading button is busy and disabled');
        $this->assertSame(1, $xpath->query('//input[@role="combobox"][@aria-controls="kit-lang-listbox"]')->length);
        $this->assertSame(1, $xpath->query('//input[@type="hidden"][@name="kit-lang"][@disabled]')->length, 'the combobox value only submits once script runs');
        $this->assertSame(1, $xpath->query('//select[@data-td-combobox-fallback][@name="kit-lang"]')->length, 'a native select works without script');
        $this->assertSame(1, $xpath->query('//*[@role="progressbar"][@aria-valuenow="7"][@aria-valuemax="12"][@aria-valuetext="7 of 12 topics"]')->length);
        $this->assertSame(1, $xpath->query('//dialog[@id="kit-dialog"][@aria-labelledby="kit-dialog-title"]')->length);
        $this->assertSame(1, $xpath->query('//div[@data-td-toasts][@aria-live="polite"]')->length);
        $this->assertSame(1, $xpath->query('//nav[contains(@class, "td-pagination")]//*[@aria-current="page"]')->length);
        $this->assertSame(1, $xpath->query('//span[@lang="zu"][text()="isiZulu"]')->length, 'a language is written in its own name and marked with its code');
        $this->assertGreaterThanOrEqual(1, $xpath->query('//div[contains(@class, "table-scroll")][@role="region"][@aria-label="Sample lessons"]')->length);

        $this->assertStringContainsString('Verified qualified tutor', $html);
        $this->assertStringContainsString('Independent tutor, self-declared, not verified', $html);
        $this->assertSame(1, $xpath->query('//span[contains(@class, "td-badge--peer")]')->length, 'a verified peer tutor never wears the professional colour');
        $this->assertStringContainsString("R250.00<span class=\"td-money__unit\"> per hour</span>", $html);
        $this->assertStringContainsString("R1\u{202F}234\u{202F}567.89", $html);
        $this->assertStringContainsString('−R50.00', $html);
    }

    public function test_the_young_step_is_set_on_the_page_and_relabels_navigation(): void
    {
        $response = $this->actingAs($this->makeUser())->get('/_test-only-ds-kit/young');
        $xpath = $this->assertResponsiveShell($response);

        $this->assertSame(1, $xpath->query('//html[@data-td-step="young"]')->length);
        $this->assertSame(1, $xpath->query('//aside[@id="app-sidebar"]//a[normalize-space(.)="Get help"]')->length);
    }

    public function test_navigation_links_only_routes_that_exist_and_marks_the_current_one(): void
    {
        $this->actingAs($this->makeUser())->get(route('tutors.browse'));
        $nav = app(ShellNavigation::class);
        $items = collect($nav->for('learner'))->flatMap(fn (array $section) => $section['items']);

        $this->assertSame(['Home', 'Find a tutor', 'Account'], $items->pluck('label')->all(), 'only items whose routes exist today');
        $this->assertTrue($items->firstWhere('label', 'Find a tutor')['current']);
        $this->assertFalse($items->firstWhere('label', 'Home')['current']);
        $this->assertSame(route('dashboard'), $items->firstWhere('label', 'Home')['href'], 'falls back to the next candidate route');
        $this->assertCount(2, $nav->bottomBar('learner'));

        $operations = collect($nav->for('operations', 'dense'))->flatMap(fn (array $section) => $section['items'])->pluck('href')->all();
        $this->assertContains(route('operator.tutor-profiles.index'), $operations);
        $this->assertContains(route('operator.subjects.index'), $operations);
    }

    public function test_the_landing_says_what_the_product_is_and_loads_nothing_from_other_hosts(): void
    {
        $response = $this->get('/');

        $response->assertOk()
            ->assertSee('Tell us what', false)
            ->assertSee('A room for every level')
            ->assertSee('Matches that explain themselves')
            ->assertSee('Learn in the language you think in')
            ->assertSee('Tshivenḓa')
            ->assertSee(route('tutor.profile.edit'), false);

        foreach (self::THIRD_PARTY_HOSTS as $host) {
            $response->assertDontSee($host, false);
        }
    }

    public function test_no_layout_or_error_page_loads_a_third_party_host(): void
    {
        $pages = [
            $this->get('/login'),
            $this->get('/does-not-exist-'.uniqid()),
            $this->actingAs($this->makeUser())->get(route('dashboard')),
        ];

        foreach ($pages as $response) {
            foreach (self::THIRD_PARTY_HOSTS as $host) {
                $response->assertDontSee($host, false);
            }
        }

        foreach (glob(resource_path('views/{layouts,layouts/partials,errors}/*.blade.php'), GLOB_BRACE) as $file) {
            foreach (self::THIRD_PARTY_HOSTS as $host) {
                $this->assertStringNotContainsString($host, file_get_contents($file), basename($file));
            }
        }
    }
}
