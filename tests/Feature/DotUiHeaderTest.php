<?php

namespace Tests\Feature;

use App\Domain\Ops\Access\OpsRole;
use App\Domain\Ops\Access\RoleAdministration;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request as OutgoingRequest;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Safety\BuildsSafetyState;
use Tests\TestCase;

/**
 * R-51: the shared Dot UI (dot-ui) in every signed-in shell of Dot.Tutor. The switcher sits in the one signed-in frame
 * (layouts/partials/frame.blade.php), so the learner, tutor workspace, operations, guardian and legacy shells all carry it.
 * It only ever shows what InfoDot says the CURRENT company holds, linking through InfoDot's /go (never straight to a host,
 * never with a token). The app has no header search, so none is added; the four in-page search filters use the shared
 * compact filter and keep their forms, routes, parameters and queries.
 */
class DotUiHeaderTest extends TestCase
{
    use BuildsSafetyState, RefreshDatabase;

    private const REDEEM = 'https://infodot.test/api/ecosystem/redeem';

    private const ACME = '11111111-1111-4111-8111-111111111111';

    private const GLOBEX = '22222222-2222-4222-8222-222222222222';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'ecosystem.infodot' => ['redeem_url' => self::REDEEM, 'platform' => 'tutor', 'secret' => 'a-test-secret', 'timeout' => 2, 'provision' => true, 'entitlement_check' => true],
            'ecosystem.platforms.infodot.url' => 'https://infodot.test',
        ]);
    }

    private static function url(string $uuid): string
    {
        return 'https://infodot.test/api/ecosystem/companies/'.$uuid.'/platforms*';
    }

    /** InfoDot's read endpoint as it answers this platform about one company. */
    private static function body(string $uuid, array $held = ['tutor', 'files', 'infodot']): array
    {
        $rows = [];
        foreach (['tutor' => 'Dot.Tutor', 'files' => 'Dot.Files', 'finance' => 'Dot.Finance', 'hr' => 'Dot.HR', 'infodot' => 'InfoDot', 'press' => 'Dot.Press'] as $key => $name) {
            $rows[] = ['key' => $key, 'name' => $name, 'status' => 'active', 'entitled' => in_array($key, $held, true), 'is_core' => false, 'access_mode' => 'everyone'];
        }

        return ['platform' => 'tutor', 'company' => ['uuid' => $uuid, 'personal' => false], 'platforms' => $rows];
    }

    private function fakeInfoDot(array $stubs): void
    {
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake($stubs);
    }

    /** A person whose current team is the InfoDot company $uuid. */
    private function memberOf(string $uuid, string $name): User
    {
        $user = User::factory()->withPersonalTeam()->create();
        $team = Team::firstWhere('infodot_team_uuid', $uuid)
            ?? Team::forceCreate(['user_id' => $user->id, 'name' => $name, 'personal_team' => false, 'infodot_team_uuid' => $uuid]);
        if (! $user->belongsToTeam($team)) {
            $team->users()->attach($user, ['role' => 'admin']);
            $user = $user->fresh(); // Jetstream caches the membership list on the model
        }
        $user->switchTeam($team);

        return $user->fresh();
    }

    private function operatorIn(User $user): User
    {
        app(RoleAdministration::class)->setRoles(null, $user, [OpsRole::Admin->value]);

        return $user->fresh();
    }

    /** The markup of the switcher only, so the assertions cannot be satisfied by the rest of the page. */
    private function switcherHtml(string $html): string
    {
        $this->assertSame(1, preg_match('/<div class="dsw dsw--header".*?<p class="dsw-sr" data-dsw-live[^>]*><\/p>\s*<\/div>\s*<\/div>\s*<\/div>/s', $html, $m), 'the header carries the switcher');

        return $m[0];
    }

    // ── Every signed-in shell carries the switcher, in the top bar ──────────

    public function test_every_signed_in_shell_has_the_switcher_in_its_top_bar(): void
    {
        $this->fakeInfoDot([self::url(self::ACME) => Http::response(self::body(self::ACME))]);
        $member = $this->memberOf(self::ACME, 'Acme Ltd');
        $operator = $this->operatorIn($this->memberOf(self::ACME, 'Acme Ltd'));

        $shells = [
            'learner shell (/learn)' => [$member, '/learn'],
            'tutor workspace shell (teach.profile)' => [$member, route('teach.profile')],
            'operations shell (/ops)' => [$operator, '/ops'],
            'legacy app shell (/dashboard)' => [$member, '/dashboard'],
        ];

        foreach ($shells as $label => [$user, $path]) {
            $html = $this->actingAs($user)->get($path)->assertOk()->getContent();

            $this->assertSame(1, substr_count($html, 'data-dsw data-state'), "{$label}: exactly one switcher");
            $this->assertStringContainsString('data-state="ok"', $html, "{$label}: a company member gets the company state");
            $this->assertSame(1, preg_match('/<header class="td-top topbar".*?<\/header>/s', $html, $bar), "{$label}: has the top bar");
            $this->assertStringContainsString('data-dsw data-state="ok" data-platform="tutor"', $bar[0], "{$label}: the switcher is inside the top bar");
            $this->assertStringContainsString('/dot-ui/css/dot-switcher.css', $html, "{$label}: shared CSS loaded");
            $this->assertStringContainsString('/dot-ui/js/dot-switcher.js', $html, "{$label}: shared script loaded");
        }
    }

    public function test_the_guardian_home_carries_the_switcher(): void
    {
        $this->fakeInfoDot([self::url(self::ACME) => Http::response(self::body(self::ACME))]);
        $child = $this->child('Sipho Child');
        $guardian = $this->adult('Thandi Guardian');
        $this->linkGuardian($child, $guardian, false, 9);
        $team = Team::forceCreate(['user_id' => $guardian->id, 'name' => 'Acme Ltd', 'personal_team' => false, 'infodot_team_uuid' => self::ACME]);
        $guardian->switchTeam($team);

        $html = $this->actingAs($guardian->fresh())->get(route('guardian.home'))->assertOk()->getContent();

        $this->assertStringContainsString('Your children on Dot.Tutor', $html);
        $this->assertStringContainsString('data-dsw data-state="ok" data-platform="tutor"', $this->switcherHtml($html));
    }

    public function test_the_app_has_no_header_search_so_no_pill_is_added_anywhere(): void
    {
        $this->fakeInfoDot([self::url(self::ACME) => Http::response(self::body(self::ACME))]);
        $member = $this->memberOf(self::ACME, 'Acme Ltd');

        foreach (['/learn', route('teach.profile'), '/dashboard', route('marketplace.find')] as $path) {
            $html = $this->actingAs($member)->get($path)->assertOk()->getContent();
            $this->assertStringNotContainsString('dsr-input', $html, "{$path}: no search pill: Dot.Tutor has no header search");
            $this->assertSame(1, preg_match('/<header class="td-top topbar".*?<\/header>/s', $html, $bar), "{$path}: has the top bar");
            $withoutSwitcher = str_replace($this->switcherHtml($html), '', $bar[0]); // the switcher has its own "Filter platforms" box
            $this->assertDoesNotMatchRegularExpression('/type="search"|role="search"/', $withoutSwitcher, "{$path}: no search input in the top bar");
        }
    }

    public function test_guests_and_public_pages_get_no_switcher_and_no_shared_css(): void
    {
        foreach ([route('login'), '/', route('marketplace.find')] as $path) {
            $this->get($path)->assertOk()
                ->assertDontSee('data-dsw', false)
                ->assertDontSee('dsr-filter', false)
                ->assertDontSee('/dot-ui/', false);
        }
    }

    // ── The in-page search filters: shared compact filter, same logic ───────

    public function test_the_four_in_page_search_filters_keep_their_form_route_and_parameter(): void
    {
        $this->fakeInfoDot([self::url(self::ACME) => Http::response(self::body(self::ACME))]);
        $member = $this->memberOf(self::ACME, 'Acme Ltd');
        $operator = $this->operatorIn($this->memberOf(self::ACME, 'Acme Ltd'));
        $cases = [
            'ops users' => [$operator, route('ops.users', ['q' => 'Zanele']), route('ops.users'), 'Zanele'],
            'course catalogue' => [$member, route('learning.courses.index', ['q' => 'fractions']), route('learning.courses.index'), 'fractions'],
            'resource library' => [$member, route('learning.library.index', ['q' => 'algebra']), route('learning.library.index'), 'algebra'],
        ];

        foreach ($cases as $label => [$user, $url, $action, $value]) {
            $html = $this->actingAs($user)->get($url)->assertOk()->getContent();

            $this->assertSame(1, preg_match('/<form method="GET" action="([^"]+)"[^>]*role="search"/', $html, $form), "{$label}: one GET search form");
            $this->assertSame($action, html_entity_decode($form[1]), "{$label}: same action");
            $this->assertMatchesRegularExpression('/<input[^>]*\bclass="dsr-filter"[^>]*>/', $html, "{$label}: the shared compact filter");
            $this->assertMatchesRegularExpression('/<input[^>]*\bname="q"[^>]*\bvalue="'.$value.'"|<input[^>]*\bvalue="'.$value.'"[^>]*\bname="q"/', $html, "{$label}: same parameter, current value kept");
            $this->assertMatchesRegularExpression('/<input[^>]*\bid="q"[^>]*>/', $html, "{$label}: the field's label still points at the input");
            $this->assertStringNotContainsString('class="td-input"', preg_match('/<input[^>]*\bname="q"[^>]*>/', $html, $in) ? $in[0] : '', "{$label}: the old input class is gone");
        }
    }

    public function test_the_ops_people_search_still_finds_by_name_and_email(): void
    {
        $this->fakeInfoDot([self::url(self::ACME) => Http::response(self::body(self::ACME))]);
        $operator = $this->operatorIn($this->memberOf(self::ACME, 'Acme Ltd'));
        User::factory()->withPersonalTeam()->create(['name' => 'Zanele Dlamini', 'email' => 'zanele@home.test']);
        User::factory()->withPersonalTeam()->create(['name' => 'Pieter Botha', 'email' => 'pieter@home.test']);

        $this->actingAs($operator)->get(route('ops.users', ['q' => 'Zanele']))->assertOk()->assertSee('Zanele Dlamini')->assertDontSee('Pieter Botha');
        $this->actingAs($operator)->get(route('ops.users', ['q' => 'pieter@home']))->assertOk()->assertSee('Pieter Botha')->assertDontSee('Zanele Dlamini');
    }

    // ── The switcher ────────────────────────────────────────────────────────

    public function test_a_member_sees_what_their_company_holds_with_the_current_platform_marked(): void
    {
        $this->fakeInfoDot([self::url(self::ACME) => Http::response(self::body(self::ACME))]);
        $owner = $this->memberOf(self::ACME, 'Acme Ltd');

        $html = $this->actingAs($owner)->get('/learn')->assertOk()->getContent();
        $panel = $this->switcherHtml($html);

        $this->assertStringContainsString('data-state="ok"', $panel);
        $this->assertStringContainsString('data-platform="tutor"', $panel);
        $this->assertStringContainsString('Acme Ltd', $panel, 'the sentence names the company');

        // Current platform: a marker, not a link.
        $this->assertSame(1, preg_match('/<div class="dsw-bay is-current" aria-current="page">.*?You are here/s', $panel, $here));
        $this->assertStringContainsString('Dot.Tutor', $here[0]);

        // Held platforms are links through InfoDot with this company; InfoDot itself goes home.
        $this->assertStringContainsString('href="https://infodot.test/go/files?company='.self::ACME.'"', $panel);
        $this->assertStringContainsString('href="https://infodot.test/home"', $panel);
        // Not held: not a tile, only in the "not switched on" list that points at InfoDot's explanation.
        $this->assertStringNotContainsString('/go/hr', $panel);
        $this->assertStringNotContainsString('/go/press', $panel);
        $this->assertStringContainsString('href="https://infodot.test/platforms/hr"', $panel);
        $this->assertStringContainsString('Not switched on (3)', $panel);

        // Never a token, never a secret, never straight to a destination host.
        $this->assertStringNotContainsString('token', strtolower($panel));
        $this->assertStringNotContainsString('a-test-secret', $html);
        preg_match_all('/href="([^"]+)"/', $panel, $hrefs);
        foreach ($hrefs[1] as $href) {
            $this->assertStringStartsWith('https://infodot.test/', $href, 'every way out of the switcher goes through InfoDot');
        }
    }

    public function test_the_switcher_asks_infodot_about_the_current_company_only_with_the_service_secret(): void
    {
        $this->fakeInfoDot([self::url(self::ACME) => Http::response(self::body(self::ACME))]);

        $this->actingAs($this->memberOf(self::ACME, 'Acme Ltd'))->get('/learn')->assertOk();

        Http::assertSent(fn (OutgoingRequest $request) => str_contains($request->url(), '/companies/'.self::ACME.'/platforms')
            && $request->hasHeader('Authorization', 'Bearer a-test-secret')
            && $request->hasHeader('X-Dot-Platform', 'tutor'));
        Http::assertNotSent(fn (OutgoingRequest $request) => str_contains($request->url(), self::GLOBEX));
    }

    public function test_two_companies_never_see_each_others_switcher(): void
    {
        $this->fakeInfoDot([
            self::url(self::ACME) => Http::response(self::body(self::ACME, ['tutor', 'infodot', 'press'])),
            self::url(self::GLOBEX) => Http::response(self::body(self::GLOBEX, ['tutor', 'infodot', 'hr'])),
        ]);

        $acme = $this->switcherHtml($this->actingAs($this->memberOf(self::ACME, 'Acme Ltd'))->get('/learn')->getContent());
        $globex = $this->switcherHtml($this->actingAs($this->memberOf(self::GLOBEX, 'Globex Ltd'))->get('/learn')->getContent());

        $this->assertStringContainsString('/go/press?company='.self::ACME, $acme);
        $this->assertStringNotContainsString('/go/hr', $acme);
        $this->assertStringNotContainsString(self::GLOBEX, $acme);
        $this->assertStringContainsString('/go/hr?company='.self::GLOBEX, $globex);
        $this->assertStringNotContainsString('/go/press', $globex);
        $this->assertStringNotContainsString(self::ACME, $globex);
    }

    public function test_a_person_with_two_companies_sees_the_switcher_of_the_current_one_only(): void
    {
        $this->fakeInfoDot([
            self::url(self::ACME) => Http::response(self::body(self::ACME, ['tutor', 'infodot', 'press'])),
            self::url(self::GLOBEX) => Http::response(self::body(self::GLOBEX, ['tutor', 'infodot', 'hr'])),
        ]);
        $user = $this->memberOf(self::ACME, 'Acme Ltd');
        $globex = Team::forceCreate(['user_id' => $user->id, 'name' => 'Globex Ltd', 'personal_team' => false, 'infodot_team_uuid' => self::GLOBEX]);

        $this->assertStringContainsString('/go/press?company='.self::ACME, $this->switcherHtml($this->actingAs($user)->get('/learn')->getContent()));

        $user->switchTeam($globex);
        $panel = $this->switcherHtml($this->actingAs($user->fresh())->get('/learn')->getContent());
        $this->assertStringContainsString('/go/hr?company='.self::GLOBEX, $panel);
        $this->assertStringNotContainsString(self::ACME, $panel);
    }

    public function test_when_infodot_cannot_be_reached_the_switcher_says_so_and_never_shows_active(): void
    {
        // The entitlement check and the switcher both get a 503: the check carries on (as it always did), the switcher degrades.
        $this->fakeInfoDot([self::url(self::ACME) => Http::response('down', 503)]);

        $html = $this->actingAs($this->memberOf(self::ACME, 'Acme Ltd'))->get('/learn')->assertOk()->getContent();
        $panel = $this->switcherHtml($html);

        $this->assertStringContainsString('data-state="degraded"', $panel);
        $this->assertStringContainsString('Could not check your platforms just now', $panel);
        $this->assertStringContainsString('You are here', $panel);
        $this->assertStringNotContainsString('Active', $panel);
        $this->assertStringNotContainsString('a-test-secret', $html);
    }

    public function test_a_timeout_degrades_the_switcher_and_the_page_still_renders(): void
    {
        $this->fakeInfoDot([self::url(self::ACME) => fn () => throw new ConnectionException('timed out')]);

        $html = $this->actingAs($this->memberOf(self::ACME, 'Acme Ltd'))->get('/learn')->assertOk()->getContent();

        $this->assertStringContainsString('data-state="degraded"', $this->switcherHtml($html));
    }

    public function test_a_personal_workspace_asks_infodot_nothing_and_shows_the_personal_state(): void
    {
        $this->fakeInfoDot(['*' => Http::response('should never be asked', 500)]);
        $solo = User::factory()->withPersonalTeam()->create();

        $html = $this->actingAs($solo)->get('/learn')->assertOk()->getContent();

        $this->assertStringContainsString('data-state="personal"', $this->switcherHtml($html));
        Http::assertNothingSent();
    }

    public function test_a_team_not_linked_to_infodot_is_treated_as_personal(): void
    {
        $this->fakeInfoDot(['*' => Http::response('should never be asked', 500)]);
        $user = User::factory()->withPersonalTeam()->create();
        $team = Team::forceCreate(['user_id' => $user->id, 'name' => 'Unlinked', 'personal_team' => false, 'infodot_team_uuid' => null]);
        $user->switchTeam($team);

        $this->assertStringContainsString('data-state="personal"', $this->switcherHtml($this->actingAs($user->fresh())->get('/learn')->getContent()));
        Http::assertNothingSent();
    }

    // ── Same origin, strict CSP ─────────────────────────────────────────────

    public function test_the_shared_assets_come_from_this_origin_and_carry_no_inline_style_or_script(): void
    {
        $this->fakeInfoDot([self::url(self::ACME) => Http::response(self::body(self::ACME))]);

        $response = $this->actingAs($this->memberOf(self::ACME, 'Acme Ltd'))->get('/learn')->assertOk();
        $html = $response->getContent();

        preg_match_all('/<(?:link|script)[^>]+(?:href|src)="([^"]*dot-ui[^"]*)"/', $html, $assets);
        $this->assertCount(6, $assets[1], 'five stylesheets and one script');
        foreach ($assets[1] as $asset) {
            $this->assertStringStartsWith(url('/dot-ui/'), $asset, 'served from this site, not another host');
            $this->assertFileExists(public_path(parse_url($asset, PHP_URL_PATH)), 'the file exists in public/dot-ui');
        }

        $panel = $this->switcherHtml($html);
        $this->assertDoesNotMatchRegularExpression('/\sstyle="/', $panel, 'no inline style in the switcher');
        $this->assertDoesNotMatchRegularExpression('/<script/i', $panel, 'no inline script in the switcher');
        $this->assertDoesNotMatchRegularExpression('/\son[a-z]+="/i', $panel, 'no inline event handlers');

        preg_match_all('/<img[^>]*\bsrc="([^"]+)"/', $panel, $logos);
        $this->assertNotEmpty($logos[1], 'logos are shown');
        foreach ($logos[1] as $src) {
            $this->assertStringStartsWith('/dot-ui/img/platforms/', $src, 'logos are the packaged originals, same origin');
            $this->assertFileExists(public_path($src));
        }

        $csp = (string) $response->headers->get('Content-Security-Policy');
        $this->assertStringContainsString("img-src 'self' data: blob:", $csp, 'the policy was not loosened for the shared UI');
        $this->assertStringContainsString("script-src 'self' 'nonce-", $csp);
        $this->assertStringNotContainsString('infodot.test', explode('form-action', $csp)[0], 'no InfoDot host in any fetch directive');
    }

    public function test_the_host_token_mapping_is_loaded_with_the_app_stylesheet(): void
    {
        $css = file_get_contents(resource_path('css/ds/dot-ui.css'));

        $this->assertStringContainsString('--dsw-btn-border: var(--td-edge)', $css);
        $this->assertStringContainsString('--dsr-panel: var(--td-surface)', $css);
        $this->assertStringContainsString("@import './ds/dot-ui.css';", file_get_contents(resource_path('css/app.css')));
    }
}
