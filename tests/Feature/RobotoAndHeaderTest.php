<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\ShellHeader;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsMarketplace;
use Tests\TestCase;

/**
 * R-52: Roboto is the one text face on every page type, served from this origin; the signed-in shells carry the shared dot-ui header (one row,
 * brand, centred search, switcher, bell, account menu, a menu button that opens the platform's own rail); nothing in it is inline.
 */
class RobotoAndHeaderTest extends TestCase
{
    use BuildsMarketplace, RefreshDatabase;

    public function test_roboto_is_declared_and_its_files_are_served_from_this_origin(): void
    {
        $css = file_get_contents(public_path('dot-ui/css/dot-roboto.css'));

        $this->assertStringContainsString("font-family: 'Roboto'", $css);
        $this->assertFileExists(public_path('dot-ui/fonts/roboto/roboto-latin-wght-normal.woff2'));
        $this->assertFileExists(public_path('dot-ui/fonts/roboto/roboto-latin-ext-wght-normal.woff2'));
        $this->assertFileExists(public_path('dot-ui/fonts/roboto/OFL.txt'));
        $this->assertStringNotContainsString('http', preg_replace('#/\*.*?\*/#s', '', $css), 'no remote font URL in the font CSS');
    }

    public function test_every_page_type_loads_roboto_and_names_no_external_font(): void
    {
        $learner = $this->makeUser('Reader');
        $pages = [
            'public landing' => [null, '/'],
            'sign in' => [null, route('login')],
            'public find' => [null, route('marketplace.find')],
            'learner home' => [$learner, '/learn'],
            'tutor profile page' => [$learner, route('teach.profile')],
            'operator desk' => [$this->makeOperator(), '/ops'],
            'search results' => [$learner, route('search')],
        ];

        foreach ($pages as $label => [$user, $path]) {
            $html = ($user ? $this->actingAs($user) : $this)->get($path)->assertOk()->getContent();

            $this->assertMatchesRegularExpression('/<html[^>]*class="dot-roboto"/', $html, "{$label}: <html> carries dot-roboto");
            $this->assertTrue(
                str_contains($html, 'dot-ui/css/dot-ui.min.css') || (str_contains($html, 'dot-ui/css/dot-roboto.min.css') && str_contains($html, 'dot-ui/css/dot-type.min.css')),
                "{$label}: Roboto and the type scale linked from dot-ui (one bundle when signed in, two small files on public pages)"
            );
            $this->assertStringNotContainsString('fonts.googleapis', $html, "{$label}: no Google Fonts");
            $this->assertStringNotContainsString('fonts.gstatic', $html, "{$label}: no Google Fonts");
            $this->assertStringNotContainsString('/fonts/archivo', $html);
            $this->assertStringNotContainsString('atkinson', strtolower($html));
        }
    }

    public function test_no_other_text_face_is_left_in_the_stylesheets_or_files(): void
    {
        foreach (['archivo', 'atkinson', 'TD Display', 'TD Text', 'TD Figures', 'Big Shoulders'] as $needle) {
            foreach (['resources/css', 'resources/views', 'resources/js', 'public/fonts'] as $dir) {
                $path = base_path($dir);
                if (! is_dir($path)) {
                    continue;
                }
                $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS));
                foreach ($files as $file) {
                    $name = $file->getPathname();
                    $this->assertStringNotContainsStringIgnoringCase($needle, basename($name), "{$name}: a file named for {$needle}");
                    if (preg_match('/\.(css|js|php|mjs)$/', $name)) {
                        $this->assertStringNotContainsStringIgnoringCase($needle, file_get_contents($name), "{$name} mentions {$needle}");
                    }
                }
            }
        }
    }

    public function test_the_shared_font_folder_holds_only_roboto(): void
    {
        $files = array_map('basename', glob(public_path('dot-ui/fonts/*/*.woff2')) ?: []);
        $this->assertNotEmpty($files);
        foreach ($files as $file) {
            $this->assertStringStartsWith('roboto-', $file);
        }
        $this->assertSame([], glob(public_path('dot-ui/fonts/*.woff2')) ?: [], 'no Archivo file beside the Roboto folder');
        $this->assertSame([], glob(public_path('dot-ui/switcher-fonts/*')) ?: []);
    }

    public function test_the_signed_in_header_is_one_shared_header_with_a_menu_button_a_search_sheet_and_no_inline_code(): void
    {
        $html = $this->actingAs($this->makeUser('Header Reader'))->get('/learn')->assertOk()->getContent();

        $this->assertSame(1, preg_match('/<header class="dh[^"]*" id="dh".*?<\/header>/s', $html, $bar), 'one shared header');
        $header = $bar[0];

        $this->assertStringContainsString('data-dh-menu-button', $header);
        $this->assertStringContainsString('aria-controls="app-sidebar"', $header, 'the menu button opens the platform rail (adopt mode)');
        $this->assertStringContainsString('dh-search-btn', $header, 'phones open the search as a sheet');
        $this->assertStringContainsString('data-dsw', $header, 'the platform switcher is in the header');
        $this->assertStringContainsString('dh-bell', $header);
        $this->assertStringContainsString('dh-account', $header);
        $this->assertStringContainsString('data-dh-theme-set="dark"', $header, 'the theme choice lives in the account menu');
        $this->assertStringContainsString('dh--adopt', $header);
        $this->assertDoesNotMatchRegularExpression('/\sstyle="/', $header, 'no inline style in the header');
        $this->assertDoesNotMatchRegularExpression('/<script/i', $header, 'no inline script in the header');
        $this->assertDoesNotMatchRegularExpression('/\son[a-z]+="/i', $header, 'no inline event handlers in the header');
        $this->assertSame(1, substr_count($html, 'id="app-sidebar"'));
    }

    public function test_the_learner_bottom_bar_stays_within_five_items(): void
    {
        $html = $this->actingAs($this->makeUser('Bar Reader'))->get('/learn')->assertOk()->getContent();

        $this->assertSame(1, preg_match('/<nav class="td-bottom".*?<\/nav>/s', $html, $bar));
        $this->assertLessThanOrEqual(5, substr_count($bar[0], '<a '));
        $this->assertGreaterThanOrEqual(1, substr_count($bar[0], '<a '));
    }

    public function test_the_shared_assets_match_dot_ui_061_and_the_drift_manifest_lists_them(): void
    {
        $this->assertStringContainsString('0.6.1', (string) file_get_contents(base_path('dot-ui/VERSION')));
        $manifest = (string) file_get_contents(base_path('dot-ui/MANIFEST.sha256'));
        foreach (['public/dot-ui/css/dot-header.css', 'public/dot-ui/css/dot-ui.min.css', 'public/dot-ui/js/dot-nav.js', 'public/dot-ui/js/dot-ui.min.js', 'public/dot-ui/js/dot-search-results.js', 'public/dot-ui/css/dot-roboto.css'] as $file) {
            $this->assertStringContainsString($file, $manifest);
        }
    }

    public function test_a_switcher_that_cannot_be_configured_falls_back_to_the_plain_state_instead_of_printing_nothing(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        config(['cache.default' => 'no-such-store']);

        $switcher = app(ShellHeader::class)->switcher($user);

        $this->assertIsArray($switcher['model']);
        $this->assertSame('tutor', $switcher['model']['current']);
        $this->assertNotSame('ok', $switcher['model']['state'], 'a switcher that could not check never claims to be active');
    }

    public function test_the_dashboard_entrance_animation_never_starts_from_opacity_zero(): void
    {
        // Chrome skips an opacity:0 paint for largest-contentful-paint and a compositor-only animation never repaints: a from-0 rise
        // moved the learner home's LCP from first paint (0.85 s) to page load (1.25 s) under the harness profile.
        $css = (string) file_get_contents(resource_path('css/ds/dashboard.css'));
        $this->assertDoesNotMatchRegularExpression('/@keyframes hm-rise\s*\{\s*from\s*\{\s*opacity:\s*0\s*;/', $css);
        $this->assertMatchesRegularExpression('/@keyframes hm-rise\s*\{\s*from\s*\{\s*opacity:\s*0\.01/', $css);
    }
}
