<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\TestCase;

/**
 * Production gives every Dot app its own database, so InfoDot's handoff token is
 * not in ours. The app asks InfoDot to prove it, then matches or creates the user.
 */
class InfoDotRedeemTest extends TestCase
{
    use RefreshDatabase;

    private const URL = 'https://infodot.test/api/ecosystem/redeem';

    protected function setUp(): void
    {
        parent::setUp();

        config(['ecosystem.infodot' => [
            'redeem_url' => self::URL,
            'platform' => 'tutor',
            'secret' => 'service-secret-for-tests',
            'timeout' => 2,
            'provision' => true,
        ]]);
    }

    private function identity(array $user = [], array $extra = []): array
    {
        return array_merge([
            'user' => array_merge(['id' => 7, 'name' => 'Amara Dlamini', 'email' => 'Amara@Example.test', 'email_verified' => true], $user),
            'teams' => [['id' => 3, 'name' => 'Amara Team', 'personal' => true, 'role' => 'owner']],
            'current_team_id' => 3,
        ], $extra);
    }

    public function test_a_person_vouched_for_by_infodot_gets_an_account_and_is_signed_in(): void
    {
        Http::fake([self::URL => Http::response($this->identity())]);

        $this->get(route('ecosystem.auth', ['token' => 'one-time-token']))->assertRedirect(route('dashboard'));

        $this->assertAuthenticated();
        $user = User::where('email', 'amara@example.test')->firstOrFail();
        $this->assertSame('Amara Dlamini', $user->name);
        $this->assertNotNull($user->email_verified_at);
        $this->assertCount(1, $user->ownedTeams);
        $this->assertTrue($user->ownedTeams->first()->personal_team);
        $this->assertSame($user->ownedTeams->first()->id, $user->current_team_id);
    }

    public function test_a_provisioned_user_gets_a_random_password_nobody_knows(): void
    {
        Http::fake([self::URL => Http::response($this->identity())]);
        $this->get(route('ecosystem.auth', ['token' => 'one-time-token']));
        $first = User::where('email', 'amara@example.test')->firstOrFail();

        Http::swap(new Factory);
        Http::fake([self::URL => Http::response($this->identity(['email' => 'second@example.test']))]);
        auth()->logout();
        $this->get(route('ecosystem.auth', ['token' => 'another-token']));
        $second = User::where('email', 'second@example.test')->firstOrFail();

        $this->assertNotSame($first->password, $second->password, 'each person gets a different random password');
        $this->assertFalse(Hash::check('password', $first->password));
        $this->assertFalse(Hash::check('', $first->password));
        $this->assertFalse(Hash::check('one-time-token', $first->password));
    }

    public function test_the_secret_and_platform_go_in_the_request_and_the_token_never_in_the_url(): void
    {
        Http::fake([self::URL => Http::response($this->identity())]);

        $this->get(route('ecosystem.auth', ['token' => 'one-time-token']));

        Http::assertSent(function (Request $request) {
            return $request->url() === self::URL
                && $request->hasHeader('Authorization', 'Bearer service-secret-for-tests')
                && $request['token'] === 'one-time-token'
                && $request['platform'] === 'tutor';
        });
    }

    public function test_an_existing_account_is_matched_by_email_without_a_duplicate(): void
    {
        $existing = User::factory()->withPersonalTeam()->create(['email' => 'amara@example.test', 'name' => 'Local Name']);
        Http::fake([self::URL => Http::response($this->identity())]);

        $this->get(route('ecosystem.auth', ['token' => 't']));

        $this->assertAuthenticatedAs($existing);
        $this->assertSame(1, User::where('email', 'amara@example.test')->count());
        $this->assertSame('Local Name', $existing->fresh()->name);
    }

    public function test_an_unverified_local_account_is_adopted_and_its_password_replaced(): void
    {
        $squatter = User::factory()->create([
            'email' => 'amara@example.test',
            'email_verified_at' => null,
            'password' => Hash::make('attacker-knows-this'),
        ]);
        Http::fake([self::URL => Http::response($this->identity())]);

        $this->get(route('ecosystem.auth', ['token' => 't']));

        $this->assertAuthenticatedAs($squatter);
        $this->assertFalse(Hash::check('attacker-knows-this', $squatter->fresh()->password));
        $this->assertNotNull($squatter->fresh()->email_verified_at);
    }

    public function test_a_token_infodot_refuses_signs_nobody_in(): void
    {
        Http::fake([self::URL => Http::response(['error' => 'invalid_request'], 403)]);

        $this->get(route('ecosystem.auth', ['token' => 'bad']))->assertForbidden();

        $this->assertGuest();
        $this->assertSame(0, User::count());
    }

    public function test_a_wrong_service_secret_is_a_forbidden_not_a_sign_in(): void
    {
        Http::fake([self::URL => Http::response(['error' => 'unauthenticated'], 401)]);

        $this->get(route('ecosystem.auth', ['token' => 't']))->assertForbidden();

        $this->assertGuest();
        $this->assertSame(0, User::count());
    }

    public function test_a_success_response_without_an_identity_signs_nobody_in(): void
    {
        Http::fake([self::URL => Http::response(['ok' => true])]);
        $this->get(route('ecosystem.auth', ['token' => 't']))->assertForbidden();

        Http::swap(new Factory);
        Http::fake([self::URL => Http::response('not json at all', 200)]);
        $this->get(route('ecosystem.auth', ['token' => 't']))->assertForbidden();

        $this->assertGuest();
        $this->assertSame(0, User::count());
    }

    public function test_an_identity_infodot_does_not_vouch_for_is_refused(): void
    {
        Http::fake([self::URL => Http::response($this->identity(['email_verified' => false]))]);

        $this->get(route('ecosystem.auth', ['token' => 't']))->assertForbidden();

        $this->assertGuest();
        $this->assertSame(0, User::count());
    }

    public function test_a_missing_or_loosely_typed_verified_flag_is_never_accepted(): void
    {
        foreach ([null, 'true', 1, '1'] as $value) {
            Http::swap(new Factory);
            $identity = $this->identity();
            $identity['user']['email_verified'] = $value;
            Http::fake([self::URL => Http::response($identity)]);

            $this->get(route('ecosystem.auth', ['token' => 't']))->assertForbidden();
        }

        Http::swap(new Factory);
        $identity = $this->identity();
        unset($identity['user']['email_verified']);
        Http::fake([self::URL => Http::response($identity)]);
        $this->get(route('ecosystem.auth', ['token' => 't']))->assertForbidden();

        $this->assertGuest();
        $this->assertSame(0, User::count());
    }

    public function test_an_unverified_identity_does_not_even_touch_an_existing_account(): void
    {
        $existing = User::factory()->create(['email' => 'amara@example.test', 'email_verified_at' => null, 'password' => Hash::make('keep-me')]);
        Http::fake([self::URL => Http::response($this->identity(['email_verified' => false]))]);

        $this->get(route('ecosystem.auth', ['token' => 't']))->assertForbidden();

        $this->assertGuest();
        $this->assertTrue(Hash::check('keep-me', $existing->fresh()->password));
        $this->assertNull($existing->fresh()->email_verified_at);
    }

    public function test_when_infodot_is_down_the_person_is_told_to_retry_not_that_they_are_forbidden(): void
    {
        Http::fake([self::URL => Http::response('boom', 500)]);
        $this->get(route('ecosystem.auth', ['token' => 't']))->assertStatus(503);

        Http::swap(new Factory);
        Http::fake([self::URL => Http::response('slow down', 429)]);
        $this->get(route('ecosystem.auth', ['token' => 't']))->assertStatus(503);

        Http::swap(new Factory);
        Http::fake(fn () => throw new ConnectionException('timeout'));
        $this->get(route('ecosystem.auth', ['token' => 't']))->assertStatus(503);

        $this->assertGuest();
        $this->assertSame(0, User::count());
    }

    public function test_without_a_secret_the_old_local_only_behaviour_remains_and_infodot_is_never_called(): void
    {
        config(['ecosystem.infodot.secret' => null]);
        Http::fake();

        $this->get(route('ecosystem.auth', ['token' => 'unknown']))->assertForbidden();

        Http::assertNothingSent();
        $this->assertGuest();
    }

    public function test_a_token_in_our_own_database_still_works_and_is_spent(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        // Inserted directly so the test does not depend on how createToken() fills columns.
        $plain = bin2hex(random_bytes(20));
        $record = PersonalAccessToken::forceCreate([
            'tokenable_type' => User::class,
            'tokenable_id' => $user->id,
            'name' => 'handoff',
            'token' => hash('sha256', $plain),
            'abilities' => ['ecosystem:read'],
        ]);
        $token = $record->id.'|'.$plain;
        Http::fake();

        $this->get(route('ecosystem.auth', ['token' => $token]))->assertRedirect(route('dashboard'));
        $this->assertAuthenticatedAs($user);
        Http::assertNothingSent();

        auth()->logout();
        $this->get(route('ecosystem.auth', ['token' => $token]))->assertForbidden();
    }

    public function test_an_expired_or_wrong_ability_local_token_is_refused(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        config(['ecosystem.infodot.secret' => null]);

        foreach ([[['ecosystem:read'], now()->subMinute()], [['read'], null]] as [$abilities, $expires]) {
            $plain = bin2hex(random_bytes(20));
            $record = PersonalAccessToken::forceCreate([
                'tokenable_type' => User::class,
                'tokenable_id' => $user->id,
                'name' => 'handoff',
                'token' => hash('sha256', $plain),
                'abilities' => $abilities,
                'expires_at' => $expires,
            ]);

            $this->get(route('ecosystem.auth', ['token' => $record->id.'|'.$plain]))->assertForbidden();
        }

        $this->assertGuest();
    }

    public function test_provisioning_can_be_switched_off(): void
    {
        config(['ecosystem.infodot.provision' => false]);
        Http::fake([self::URL => Http::response($this->identity())]);

        $this->get(route('ecosystem.auth', ['token' => 't']))->assertForbidden();

        $this->assertSame(0, User::count());
    }

    public function test_a_missing_or_non_string_token_is_forbidden_without_calling_infodot(): void
    {
        Http::fake();

        $this->get(route('ecosystem.auth'))->assertForbidden();
        $this->get('/auth/ecosystem?token=')->assertForbidden();
        $this->get('/auth/ecosystem?token[]=x')->assertForbidden();
        $this->get('/auth/ecosystem?token[a][b]=x')->assertForbidden();

        Http::assertNothingSent();
        $this->assertGuest();
    }

    public function test_a_safe_deep_link_is_followed_after_sign_in(): void
    {
        Http::fake([self::URL => Http::response($this->identity())]);

        $this->get(route('ecosystem.auth', ['token' => 't', 'redirect' => '/profile/settings?tab=security']))
            ->assertRedirect(url('/profile/settings?tab=security'));

        $this->assertAuthenticated();
    }

    public function test_open_redirect_attempts_fall_back_to_the_dashboard(): void
    {
        $attempts = [
            'https://evil.test/phish',
            '//evil.test/phish',
            '///evil.test',
            '/\\evil.test',
            '\\\\evil.test',
            'evil.test/phish',
            'javascript:alert(1)',
            '/../../etc/passwd',
            '/a/../b',
            '/%2e%2e/secret',
            '/%2F%2Fevil.test',
            "/ok\r\nLocation: https://evil.test",
            ['https://evil.test'],
            '',
        ];

        foreach ($attempts as $attempt) {
            Http::swap(new Factory);
            Http::fake([self::URL => Http::response($this->identity())]);
            auth()->logout();
            Cache::flush(); // the throttle has its own test; this loop makes more than 10 requests

            $this->get(route('ecosystem.auth', ['token' => 't', 'redirect' => $attempt]))
                ->assertRedirect(route('dashboard'));
        }
    }

    public function test_the_session_id_changes_at_sign_in(): void
    {
        Http::fake([self::URL => Http::response($this->identity())]);
        $this->startSession();
        $before = session()->getId();

        $this->get(route('ecosystem.auth', ['token' => 't']))->assertRedirect();

        $this->assertNotSame($before, session()->getId());
    }

    public function test_the_endpoint_is_throttled(): void
    {
        Http::fake();

        for ($i = 0; $i < 10; $i++) {
            $this->get(route('ecosystem.auth', ['token' => 'guess-'.$i]))->assertForbidden();
        }

        $this->get(route('ecosystem.auth', ['token' => 'guess-11']))->assertStatus(429);
    }

    public function test_provisioning_never_makes_anyone_a_platform_operator(): void
    {
        // Even if the payload tried to say so, the operator flag is local-only.
        Http::fake([self::URL => Http::response($this->identity(['is_platform_operator' => true, 'role' => 'platform_operator'], ['is_platform_operator' => true]))]);

        $this->get(route('ecosystem.auth', ['token' => 't']))->assertRedirect();

        $user = User::where('email', 'amara@example.test')->firstOrFail();
        $this->assertFalse($user->is_platform_operator);
    }

    public function test_an_existing_account_never_gains_the_operator_flag_and_an_operator_keeps_it(): void
    {
        $plain = User::factory()->withPersonalTeam()->create(['email' => 'amara@example.test']);
        $operator = User::factory()->withPersonalTeam()->create(['email' => 'boss@example.test']);
        $operator->forceFill(['is_platform_operator' => true])->save();

        Http::fake([self::URL => Http::response($this->identity(['is_platform_operator' => true], ['is_platform_operator' => true]))]);
        $this->get(route('ecosystem.auth', ['token' => 't']))->assertRedirect();
        $this->assertAuthenticatedAs($plain);
        $this->assertFalse($plain->fresh()->is_platform_operator);

        Http::swap(new Factory);
        Http::fake([self::URL => Http::response($this->identity(['email' => 'boss@example.test']))]);
        auth()->logout();
        $this->get(route('ecosystem.auth', ['token' => 't']))->assertRedirect();
        $this->assertAuthenticatedAs($operator);
        $this->assertTrue($operator->fresh()->is_platform_operator, 'sign-in through InfoDot does not strip a local operator');
    }

    public function test_a_provisioned_user_is_kept_out_of_the_operator_area(): void
    {
        Http::fake([self::URL => Http::response($this->identity())]);
        $this->get(route('ecosystem.auth', ['token' => 't']))->assertRedirect();

        $this->get(route('operator.tutor-profiles.index'))->assertForbidden();
    }
}
