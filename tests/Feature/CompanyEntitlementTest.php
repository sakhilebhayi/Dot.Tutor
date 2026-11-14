<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureCompanyEntitled;
use App\Models\Team;
use App\Models\TutorProfile;
use App\Models\User;
use App\Services\InfoDot\EntitlementClient;
use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Livewire\Mechanisms\PersistentMiddleware\PersistentMiddleware;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * R-50: Dot.Tutor honours the company's entitlement. At sign-in only companies InfoDot marks entitled are admitted;
 * during a session the signed-in area asks InfoDot whether the current company still holds the platform. A clear
 * "no" is the plain 403 page; every failure lets the person carry on. Response bodies follow InfoDot's own tests.
 */
class CompanyEntitlementTest extends TestCase
{
    use RefreshDatabase;

    private const REDEEM = 'https://infodot.test/api/ecosystem/redeem';

    private const ACME = '11111111-1111-4111-8111-111111111111';

    private const GLOBEX = '22222222-2222-4222-8222-222222222222';

    private const PERSONAL = '44444444-4444-4444-8444-444444444444';

    private string $credential;

    protected function setUp(): void
    {
        parent::setUp();

        $this->credential = Str::random(40);

        config(['ecosystem.infodot' => [
            'redeem_url' => self::REDEEM, 'platform' => 'tutor', 'secret' => $this->credential, 'timeout' => 2, 'provision' => true,
            'entitlement_check' => true,
        ]]);
        config(['ecosystem.platforms.infodot.url' => 'https://infodot.test']);
    }

    // ------------------------------------------------------------------ helpers

    private static function readUrl(string $uuid): string
    {
        return 'https://infodot.test/api/ecosystem/companies/'.$uuid.'/platforms*';
    }

    private static function platforms(string $uuid, bool $entitled, string $status): array
    {
        return [
            'platform' => 'tutor',
            'company' => ['uuid' => $uuid, 'personal' => false],
            'platforms' => [
                ['key' => 'finance', 'name' => 'Dot.Finance', 'status' => 'included', 'entitled' => true, 'is_core' => true],
                ['key' => 'hr', 'name' => 'Dot.HR', 'status' => 'free_while_pricing_finalised', 'entitled' => false, 'is_core' => false],
                ['key' => 'infodot', 'name' => 'InfoDot', 'status' => 'included', 'entitled' => true, 'is_core' => true],
                ['key' => 'tutor', 'name' => 'Dot.Tutor', 'status' => $status, 'entitled' => $entitled, 'is_core' => false, 'access_mode' => 'everyone'],
            ],
        ];
    }

    private function infodotSays(array $stubs): void
    {
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake($stubs);
        $this->settleTheSwitcher();
    }

    /**
     * R-51: every signed-in page now also draws the shared platform switcher, which reads the SAME InfoDot endpoint under
     * its own cache key (infodot:platforms:...). These tests count and order the ENTITLEMENT check's reads, so the
     * switcher's cache is pre-filled with a settled answer and its read never reaches the faked endpoint. The switcher's own
     * reads are tested in DotUiHeaderTest.
     */
    private function settleTheSwitcher(): void
    {
        $rows = [['key' => 'tutor', 'name' => 'Dot.Tutor', 'entitled' => true, 'plain' => false, 'status' => 'active'], ['key' => 'infodot', 'name' => 'InfoDot', 'entitled' => true, 'plain' => false, 'status' => 'included']];

        foreach ([self::ACME, self::GLOBEX, self::PERSONAL, '33333333-3333-4333-8333-333333333333'] as $uuid) {
            Cache::forever('infodot:platforms:tutor:'.$uuid, ['ok' => true, 'personal' => false, 'rows' => $rows]);
        }
    }

    /** @return array{0: User, 1: Team} a person and their InfoDot company, which is their current team */
    private function company(string $uuid = self::ACME, string $name = 'Acme Ltd', ?User $owner = null): array
    {
        $owner ??= User::factory()->withPersonalTeam()->create();
        $team = Team::forceCreate(['user_id' => $owner->id, 'name' => $name, 'personal_team' => false, 'infodot_team_uuid' => $uuid]);
        $owner->forceFill(['current_team_id' => $team->id])->save();

        return [$owner->fresh(), $team];
    }

    private function redeemTeam(string $uuid, string $name, string $role, ?bool $entitled = null, ?string $access = null, bool $personal = false): array
    {
        $team = ['id' => random_int(1, 999), 'uuid' => $uuid, 'name' => $name, 'personal' => $personal, 'role' => $role];

        return $entitled === null ? $team : $team + ['entitled' => $entitled, 'access' => $access];
    }

    private function signIn(string $email, array $teams, ?string $current = null)
    {
        $this->infodotSays([self::REDEEM => Http::response([
            'platform' => 'tutor',
            'user' => ['id' => 1, 'uuid' => (string) Str::uuid(), 'name' => ucfirst(strstr($email, '@', true)), 'email' => $email, 'email_verified' => true],
            'teams' => $teams,
            'current_team_id' => 1,
            'current_team_uuid' => $current,
        ])]);

        auth()->logout();

        return $this->post(route('ecosystem.auth'), ['token' => 'one-time'], ['Origin' => 'https://infodot.test']);
    }

    /** The four role homes and a few pages beside them. */
    private static function homes(): array
    {
        return ['/learn', '/teach', '/ops', '/guardian', '/dashboard'];
    }

    // ------------------------------------------------------------------ sign-in (redeem)

    public function test_a_company_marked_entitled_is_admitted_at_sign_in(): void
    {
        $this->signIn('ann@acme.test', [$this->redeemTeam(self::ACME, 'Acme Ltd', 'owner', true)], self::ACME)->assertRedirect('/learn');

        $user = User::where('email', 'ann@acme.test')->firstOrFail();
        $team = Team::where('infodot_team_uuid', self::ACME)->firstOrFail();

        $this->assertAuthenticatedAs($user);
        $this->assertSame($team->id, $user->current_team_id);
    }

    public function test_a_company_marked_not_entitled_is_not_created_updated_or_made_current(): void
    {
        [, $globex] = $this->company(self::GLOBEX, 'Globex (old name)');
        $ann = User::factory()->withPersonalTeam()->create(['email' => 'ann@acme.test']);
        $globex->users()->attach($ann->id, ['role' => 'viewer']);
        $ann->forceFill(['current_team_id' => $globex->id])->save();

        $this->signIn('ann@acme.test', [
            $this->redeemTeam(self::ACME, 'Acme Ltd', 'owner', true),
            $this->redeemTeam(self::GLOBEX, 'Globex Corporation', 'admin', false, 'suspended'),
            $this->redeemTeam('33333333-3333-4333-8333-333333333333', 'Initech', 'owner', false, 'not_activated'),
        ], self::GLOBEX)->assertRedirect();

        $ann->refresh();
        $acme = Team::where('infodot_team_uuid', self::ACME)->firstOrFail();

        $this->assertNull(Team::where('infodot_team_uuid', '33333333-3333-4333-8333-333333333333')->first());
        $this->assertSame('Globex (old name)', $globex->fresh()->name);
        $this->assertSame('viewer', $globex->users()->where('users.id', $ann->id)->first()->membership->role);
        $this->assertTrue($ann->belongsToTeam($globex));
        $this->assertSame($acme->id, $ann->current_team_id);
    }

    public function test_without_the_key_sign_in_behaves_as_before(): void
    {
        $this->signIn('ann@acme.test', [
            $this->redeemTeam(self::ACME, 'Acme Ltd', 'owner'),
            $this->redeemTeam(self::GLOBEX, 'Globex', 'admin'),
        ], self::GLOBEX)->assertRedirect('/learn');

        $user = User::where('email', 'ann@acme.test')->firstOrFail();

        $this->assertNotNull(Team::where('infodot_team_uuid', self::ACME)->first());
        $this->assertSame(Team::where('infodot_team_uuid', self::GLOBEX)->firstOrFail()->id, $user->current_team_id);
        $this->assertFalse(session()->has(EnsureCompanyEntitled::PERSONAL));
    }

    public function test_when_no_company_is_entitled_the_person_gets_the_plain_page_and_is_not_signed_in(): void
    {
        $response = $this->signIn('ann@acme.test', [
            $this->redeemTeam(self::PERSONAL, "Ann's Team", 'owner', false, 'no_personal_access', personal: true),
            $this->redeemTeam(self::ACME, 'Acme Ltd', 'owner', false, 'payment_required'),
        ], self::ACME);

        $response->assertStatus(403)
            ->assertSee('Dot.Tutor needs a payment for Acme Ltd')
            ->assertSee('href="https://infodot.test/platforms/tutor"', false)
            ->assertSee('Open Platforms in InfoDot')
            ->assertDontSee('Whoops')
            ->assertDontSee('Stack trace');

        $this->assertSame(1, preg_match_all('/\sdata-primary-action[\s=>]/', $response->getContent()));
        $this->assertGuest();
        $this->assertNull(User::where('email', 'ann@acme.test')->first());
        $this->assertSame(0, Team::whereNotNull('infodot_team_uuid')->count());
    }

    public function test_an_individual_with_only_a_personal_workspace_is_told_a_company_is_needed(): void
    {
        $this->signIn('lerato@home.test', [$this->redeemTeam(self::PERSONAL, "Lerato's Team", 'owner', false, 'no_personal_access', personal: true)], self::PERSONAL)
            ->assertStatus(403)
            ->assertSee('Dot.Tutor needs a company that has switched it on')
            ->assertSee('href="https://infodot.test/platforms/tutor"', false);

        $this->assertGuest();
    }

    public function test_the_page_escapes_the_company_name(): void
    {
        $this->signIn('ann@acme.test', [$this->redeemTeam(self::ACME, '<script>alert(1)</script>', 'owner', false, 'not_activated')], self::ACME)
            ->assertStatus(403)
            ->assertDontSee('<script>alert(1)</script>', false);
    }

    // ------------------------------------------------------------------ during a session

    public function test_an_entitled_company_carries_on(): void
    {
        [$owner] = $this->company();
        $this->infodotSays([self::readUrl(self::ACME) => Http::response(self::platforms(self::ACME, true, 'active'))]);

        $this->actingAs($owner)->get('/learn')->assertOk();

        Http::assertSent(fn (Request $request) => $request->method() === 'GET'
            && str_starts_with($request->url(), 'https://infodot.test/api/ecosystem/companies/'.self::ACME.'/platforms')
            && $request['platform'] === 'tutor'
            && $request->hasHeader('X-Dot-Platform', 'tutor')
            && $request->hasHeader('Authorization', 'Bearer '.$this->credential));
    }

    public static function refusals(): array
    {
        return [
            'not switched on' => ['free_while_pricing_finalised', 'Dot.Tutor is not switched on for Acme Ltd'],
            'suspended' => ['suspended', 'Dot.Tutor is suspended for Acme Ltd'],
            'payment needed' => ['payment_required', 'Dot.Tutor needs a payment for Acme Ltd'],
            'ended' => ['subscription_expired', 'Dot.Tutor has ended for Acme Ltd'],
        ];
    }

    #[DataProvider('refusals')]
    public function test_a_company_that_no_longer_holds_the_platform_gets_403_on_every_role_home_without_a_redirect(string $status, string $title): void
    {
        [$owner] = $this->company();
        $owner->forceFill(['is_platform_operator' => true])->save();
        $this->infodotSays([self::readUrl(self::ACME) => Http::response(self::platforms(self::ACME, false, $status))]);

        foreach (self::homes() as $url) {
            $response = $this->actingAs($owner->fresh())->get($url);

            $response->assertStatus(403)
                ->assertSee($title)
                ->assertSee('href="https://infodot.test/platforms/tutor"', false);
            $this->assertFalse($response->headers->has('Location'), $url.' must not redirect');
            $this->assertSame(1, preg_match_all('/\sdata-primary-action[\s=>]/', $response->getContent()), $url);
        }
    }

    public function test_a_refused_company_changes_nothing_and_shows_no_data(): void
    {
        [$owner] = $this->company();
        $this->infodotSays([self::readUrl(self::ACME) => Http::response(self::platforms(self::ACME, false, 'suspended'))]);

        // A state-changing POST (becoming a tutor) is refused and writes nothing.
        $this->actingAs($owner)->post('/tutor/profile', ['headline' => 'Maths', 'bio' => 'Eight years.', 'hourly_rate' => '300', 'qualifications' => 'BSc', 'timezone' => 'Africa/Johannesburg', 'subjects' => []])
            ->assertStatus(403);
        $this->assertSame(0, TutorProfile::count());

        // Its JSON equivalent is refused as JSON, with {error, message} and nothing else.
        $json = $this->actingAs($owner)->postJson('/api/tutor/money/quote', ['items' => []])->assertStatus(403);
        $this->assertSame('forbidden', $json->json('error'));
        $this->assertStringContainsString('Nothing was changed', $json->json('message'));

        $this->actingAs($owner)->getJson('/api/tutor/money/orders')->assertStatus(403);
    }

    public static function failures(): array
    {
        return [
            'bad secret (401)' => [fn () => Http::response(['error' => 'invalid_request'], 401)],
            'platform switched off (403)' => [fn () => Http::response(['error' => 'invalid_request'], 403)],
            'unknown company (404)' => [fn () => Http::response(['error' => 'not_found'], 404)],
            'throttled (429)' => [fn () => Http::response('', 429)],
            'server error (500)' => [fn () => Http::response('boom', 500)],
            'not json' => [fn () => Http::response('<html>maintenance</html>', 200)],
            'no platforms list' => [fn () => Http::response(['platform' => 'tutor', 'company' => ['uuid' => self::ACME, 'personal' => false]])],
            'own row missing' => [fn () => Http::response(['platform' => 'tutor', 'company' => ['uuid' => self::ACME, 'personal' => false], 'platforms' => [
                ['key' => 'hr', 'name' => 'Dot.HR', 'status' => 'suspended', 'entitled' => false, 'is_core' => false],
            ]])],
            'entitled is not a boolean' => [fn () => Http::response(['platform' => 'tutor', 'platforms' => [['key' => 'tutor', 'status' => 'suspended', 'entitled' => 'false']]])],
            'answer for another platform' => [fn () => Http::response(['platform' => 'hr', 'platforms' => [['key' => 'tutor', 'status' => 'suspended', 'entitled' => false]]])],
            'connection error or timeout' => [fn () => fn () => throw new ConnectionException('timed out')],
        ];
    }

    #[DataProvider('failures')]
    public function test_any_failure_lets_the_person_carry_on_and_is_logged_once(Closure $answer): void
    {
        [$owner] = $this->company();
        $calls = 0;
        $reply = $answer();
        $this->infodotSays([self::readUrl(self::ACME) => function () use (&$calls, $reply) {
            $calls++;

            return $reply instanceof Closure ? $reply() : $reply;
        }]);
        Log::spy();

        $this->actingAs($owner)->get('/learn')->assertOk();
        $this->actingAs($owner)->get('/dashboard')->assertOk();

        $this->assertSame(1, $calls, 'one call for the cache window');
        Log::shouldHaveReceived('warning')->once()->withArgs(fn ($message) => str_contains($message, 'InfoDot entitlement check gave no answer'));
    }

    public function test_a_failure_is_remembered_for_a_minute_and_an_answer_for_ten(): void
    {
        [$owner] = $this->company();
        $this->infodotSays([self::readUrl(self::ACME) => Http::sequence()
            ->push('boom', 500)
            ->push(self::platforms(self::ACME, false, 'suspended'))
            ->push(self::platforms(self::ACME, true, 'active'))]);

        $this->actingAs($owner)->get('/learn')->assertOk();

        $this->travel(EntitlementClient::FAILURE_TTL + 1)->seconds();
        $this->actingAs($owner)->get('/learn')->assertStatus(403);

        $this->travel(EntitlementClient::ANSWER_TTL - 5)->seconds();
        $this->actingAs($owner)->get('/learn')->assertStatus(403);
        Http::assertSentCount(2);

        $this->travel(10)->seconds();
        $this->actingAs($owner)->get('/learn')->assertOk();
        Http::assertSentCount(3);
    }

    public function test_no_secret_configured_carries_on_without_calling(): void
    {
        [$owner] = $this->company();
        config(['ecosystem.infodot.secret' => null]);
        $this->infodotSays([]);

        $this->actingAs($owner)->get('/learn')->assertOk();

        Http::assertNothingSent();
    }

    public function test_the_answer_is_cached_per_company(): void
    {
        [$owner] = $this->company();
        $this->infodotSays([self::readUrl(self::ACME) => Http::response(self::platforms(self::ACME, true, 'active'))]);

        $this->actingAs($owner)->get('/learn')->assertOk();
        $this->actingAs($owner)->get('/dashboard')->assertOk();

        Http::assertSentCount(1);
    }

    public function test_with_the_flag_off_no_call_is_made(): void
    {
        [$owner] = $this->company();
        config(['ecosystem.infodot.entitlement_check' => false]);
        $this->infodotSays([]);

        $this->actingAs($owner)->get('/learn')->assertOk();
        $this->actingAs($owner)->get('/dashboard')->assertOk();

        Http::assertNothingSent();
    }

    public function test_the_flag_is_on_by_default(): void
    {
        $this->assertTrue((require config_path('ecosystem.php'))['infodot']['entitlement_check']);
        $this->assertStringContainsString('TUTOR_ENTITLEMENT_CHECK=true', file_get_contents(base_path('.env.example')));
    }

    // ------------------------------------------------------------------ personal workspaces (individuals hold the free core only)

    /** Ann signs in with one entitled company and a personal workspace marked as given, then works in her personal team. */
    private function signInThenWorkPersonally(?bool $personalEntitled): array
    {
        $this->signIn('ann@acme.test', [
            $this->redeemTeam(self::PERSONAL, "Ann's Team", 'owner', $personalEntitled, $personalEntitled === false ? 'no_personal_access' : null, personal: true),
            $this->redeemTeam(self::ACME, 'Acme Ltd', 'owner', $personalEntitled === null ? null : true),
        ], self::ACME)->assertRedirect();

        // The test client does not carry the session between requests; hand over what sign-in remembered.
        $this->withSession(session()->has(EnsureCompanyEntitled::PERSONAL) ? [EnsureCompanyEntitled::PERSONAL => session(EnsureCompanyEntitled::PERSONAL)] : []);

        $ann = User::where('email', 'ann@acme.test')->firstOrFail();
        $personal = $ann->personalTeam();
        $ann->forceFill(['current_team_id' => $personal->id])->save();

        $this->actingAs($ann->fresh());

        return [$ann->fresh(), $personal, Team::where('infodot_team_uuid', self::ACME)->firstOrFail()];
    }

    public function test_a_personal_workspace_infodot_marked_not_entitled_is_refused_without_asking_the_read_endpoint(): void
    {
        [$ann, $personal, $acme] = $this->signInThenWorkPersonally(false);
        $this->assertFalse(session(EnsureCompanyEntitled::PERSONAL));

        $this->infodotSays([self::readUrl(self::ACME) => Http::response(self::platforms(self::ACME, true, 'active'))]);

        // /ops is for platform operators only: an existing guard answers a non-operator there before this check.
        foreach (array_diff(self::homes(), ['/ops']) as $url) {
            $response = $this->get($url);
            $this->assertStringContainsString('Dot.Tutor needs a company that has switched it on', $response->getContent(), $url);
            $response->assertStatus(403)
                ->assertSee('href="https://infodot.test/platforms/tutor"', false)
                ->assertSee('name="team_id" value="'.$acme->id.'"', false);

            $this->assertFalse($response->headers->has('Location'), $url);
            $this->assertSame(1, preg_match_all('/\sdata-primary-action[\s=>]/', $response->getContent()), $url);
        }

        Http::assertNothingSent();

        // Nothing is deleted or altered; the own account page still opens; the entitled company can be switched to.
        $this->assertNotNull($personal->fresh());
        $this->get(route('profile.show'))->assertOk();
        $this->put(route('current-team.update'), ['team_id' => $acme->id])->assertRedirect();
        $this->get('/learn')->assertOk();
    }

    public function test_a_team_with_no_infodot_company_is_refused_the_same_way(): void
    {
        [$ann] = $this->signInThenWorkPersonally(false);
        $local = Team::forceCreate(['user_id' => $ann->id, 'name' => 'Local Co', 'personal_team' => false]);
        $ann->forceFill(['current_team_id' => $local->id])->save();
        $this->infodotSays([]);

        $this->get('/learn')->assertStatus(403)->assertSee('Dot.Tutor needs a company that has switched it on');
        Http::assertNothingSent();
    }

    public function test_a_personal_workspace_infodot_marked_entitled_carries_on(): void
    {
        $this->signInThenWorkPersonally(true);
        $this->assertTrue(session(EnsureCompanyEntitled::PERSONAL));
        $this->infodotSays([]);

        $this->get('/learn')->assertOk();
        Http::assertNothingSent();
    }

    public function test_with_no_key_at_sign_in_nothing_is_remembered_and_the_personal_workspace_carries_on(): void
    {
        $this->signInThenWorkPersonally(null);
        $this->assertFalse(session()->has(EnsureCompanyEntitled::PERSONAL));
        $this->infodotSays([]);

        $this->get('/learn')->assertOk();
        Http::assertNothingSent();
    }

    public function test_a_later_sign_in_without_the_key_forgets_an_earlier_refusal(): void
    {
        $this->signInThenWorkPersonally(false);
        $this->signIn('ann@acme.test', [$this->redeemTeam(self::PERSONAL, "Ann's Team", 'owner', personal: true), $this->redeemTeam(self::ACME, 'Acme Ltd', 'owner')], self::ACME)->assertRedirect();

        $this->assertFalse(session()->has(EnsureCompanyEntitled::PERSONAL));
    }

    public function test_the_personal_refusal_is_off_with_the_flag(): void
    {
        $this->signInThenWorkPersonally(false);
        config(['ecosystem.infodot.entitlement_check' => false]);

        $this->get('/learn')->assertOk();
    }

    // ------------------------------------------------------------------ live updates

    public function test_a_livewire_update_is_refused_once_the_company_loses_the_platform(): void
    {
        [$owner, $team] = $this->company();
        $this->infodotSays([self::readUrl(self::ACME) => Http::sequence()
            ->push(self::platforms(self::ACME, true, 'active'))
            ->push(self::platforms(self::ACME, false, 'suspended'))]);
        $this->actingAs($owner);

        $page = $this->get(route('teams.show', $team))->assertOk()->getContent();
        preg_match_all('/wire:snapshot="([^"]+)"/', $page, $all);
        $snapshot = collect($all[1])->map(fn ($s) => html_entity_decode($s, ENT_QUOTES))->first(fn ($s) => str_contains($s, 'update-team-name-form'));
        $this->assertNotNull($snapshot, 'the team name form is a Livewire component on the page');

        $rename = fn (string $name) => $this->postJson(Livewire::getUpdateUri(), ['components' => [[
            'snapshot' => $snapshot,
            'updates' => ['state.name' => $name],
            'calls' => [['path' => '', 'method' => 'updateTeamName', 'params' => []]],
        ]]], ['X-Livewire' => 'true']);

        // While the company holds the platform the action works.
        $worked = $rename('Acme Renamed');
        $this->assertNotSame(403, $worked->getStatusCode());
        $this->assertSame('Acme Renamed', $team->fresh()->name);

        // The cached answer expires, InfoDot now says no: the same action is refused and returns no component.
        $this->travel(EntitlementClient::ANSWER_TTL + 1)->seconds();
        $response = $rename('Acme Hijacked');

        $this->assertSame(403, $response->getStatusCode());
        $this->assertArrayNotHasKey('components', (array) $response->json());
        $this->assertSame('Acme Renamed', $team->fresh()->name, 'the data is unchanged');
    }

    public function test_livewire_updates_from_the_signed_in_area_carry_the_check(): void
    {
        $this->assertContains(EnsureCompanyEntitled::class, app(PersistentMiddleware::class)->getPersistentMiddleware());
    }

    // ------------------------------------------------------------------ exempt routes

    public function test_exempt_routes_make_no_call(): void
    {
        [$owner] = $this->company();
        $this->infodotSays([]);

        // Signed out: public pages, health, sign-in, the handoff receiver's legacy GET.
        $this->get('/')->assertOk();
        $this->get('/login')->assertOk();
        $this->get('/up')->assertOk();
        $this->get('/find-a-tutor')->assertOk();
        $this->get('/auth/ecosystem')->assertRedirect('/login');

        // Signed in, in a company that would be refused if anyone asked: the same, the person's own account, signing out.
        $this->actingAs($owner);
        $this->get('/')->assertOk();
        $this->get('/find-a-tutor')->assertOk();
        $this->get(route('profile.show'))->assertOk();
        $this->get('/up')->assertOk();
        $this->post(route('logout'))->assertRedirect();

        Http::assertNothingSent();
    }

    public function test_the_payment_webhook_and_other_machine_routes_are_never_checked(): void
    {
        [$owner] = $this->company();
        $this->infodotSays([self::readUrl(self::ACME) => Http::response(self::platforms(self::ACME, false, 'suspended'))]);

        $this->postJson('/api/tutor/money/webhooks/paystack', ['event' => 'charge.success'])->assertStatus(401);
        $this->post('/csp-report', [])->assertStatus(204);

        Http::assertNothingSent();
        $this->assertNotNull($owner);
    }

    public function test_a_second_company_that_is_entitled_can_still_be_switched_to(): void
    {
        [$owner, $acme] = $this->company();
        [, $globex] = $this->company(self::GLOBEX, 'Globex Corporation', $owner);
        $owner->forceFill(['current_team_id' => $acme->id])->save();

        $this->infodotSays([
            self::readUrl(self::ACME) => Http::response(self::platforms(self::ACME, false, 'suspended')),
            self::readUrl(self::GLOBEX) => Http::response(self::platforms(self::GLOBEX, true, 'active')),
        ]);

        $this->actingAs($owner->fresh())->get('/learn')->assertStatus(403)
            ->assertSee('Globex Corporation')
            ->assertSee('action="'.route('current-team.update').'"', false)
            ->assertSee('name="team_id" value="'.$globex->id.'"', false);

        $this->put(route('current-team.update'), ['team_id' => $globex->id])->assertRedirect();
        $this->assertSame($globex->id, $owner->fresh()->current_team_id);

        $this->actingAs($owner->fresh())->get('/learn')->assertOk();
    }

    public function test_another_companys_data_is_never_asked_about_by_naming_it_in_the_address(): void
    {
        [$owner] = $this->company();
        [, $other] = $this->company(self::GLOBEX, 'Globex Corporation');
        $this->infodotSays([self::readUrl(self::ACME) => Http::response(self::platforms(self::ACME, true, 'active'))]);

        $this->actingAs($owner)->get(route('teams.show', $other))->assertForbidden()->assertDontSee('Globex Corporation');

        Http::assertNotSent(fn (Request $request) => str_contains($request->url(), self::GLOBEX));
    }
}
