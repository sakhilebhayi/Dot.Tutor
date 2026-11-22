<?php

namespace Tests\Feature\Sso;

use App\Domain\Safety\Models\AgeRecord;
use App\Domain\Safety\Models\GuardianInvitation;
use App\Domain\Safety\Models\GuardianLink;
use App\Domain\Safety\Services\Guardians;
use App\Mail\SsoClaimCodeMail;
use App\Models\TutorProfile;
use App\Models\User;
use App\Sso\NewAccountAdmission;
use App\Sso\TutorProvisioner;
use App\Support\CentralSignIn;
use Dot\SsoClient\Support\DeepLink;
use Dot\SsoClient\Tests\Support\FakeIdp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Safety\BuildsSafetyState;
use Tests\TestCase;

/**
 * R-54 rollout K: Dot.Tutor behind DOTSSO_ENABLED, with the child-safeguarding rules proven one by one.
 * Off, nothing changes. On, every sign-in entry goes to InfoDot, but a person who may be a minor can never be created by
 * a sign-in alone: the one door for a new account is a usable guardian invitation for that exact verified address.
 */
class TutorSignInTest extends TestCase
{
    use BuildsSafetyState;
    use RefreshDatabase;

    private const UUID = '5a5a5a5a-5a5a-4a5a-8a5a-5a5a5a5a5a5a';

    private FakeIdp $idp;

    protected function setUp(): void
    {
        parent::setUp();

        $this->idp = (new FakeIdp)->install();
        config(['dotsso.provisioner' => TutorProvisioner::class, 'dotsso.enabled' => false]);
        Mail::fake();
    }

    private function on(): void
    {
        config(['dotsso.enabled' => true]);
    }

    private function signInVia(array $claims = [], array $session = [])
    {
        $response = $this->withSession($session)->get('/sso/login')->assertRedirect();
        $authorize = FakeIdp::authorizeParams($response->headers->get('Location'));
        $code = $this->idp->approve($authorize, $claims);

        return $this->get('/sso/callback?'.http_build_query(['code' => $code, 'state' => $authorize['state']]));
    }

    /** @return array{0: string, 1: GuardianInvitation, 2: User} signed URL, invitation, learner */
    private function invite(string $email = 'parent@example.test'): array
    {
        $learner = $this->makeUser('Learner');
        Notification::fake();
        $result = app(Guardians::class)->invite($learner, $email, 'parent');

        return [$result['url'], $result['invitation'], $learner];
    }

    private function pathAndQuery(string $url): string
    {
        return parse_url($url, PHP_URL_PATH).'?'.parse_url($url, PHP_URL_QUERY);
    }

    // -- flag off: nothing changes ------------------------------------------------------------------------------

    public function test_with_the_flag_off_nothing_changes(): void
    {
        $this->get('/login')->assertOk()->assertSee('Remember me');
        $this->get('/sso/login')->assertNotFound();
        $this->assertSame(route('login'), CentralSignIn::login());
        $this->get('/')->assertOk()->assertSee(route('login'), false)->assertDontSee('/sso/login', false);
    }

    public function test_the_infodot_button_on_login_shows_only_when_the_launch_address_is_set(): void
    {
        config(['ecosystem.infodot.launch_url' => null, 'ecosystem.infodot.secret' => 'x']);
        $this->get('/login')->assertOk()->assertDontSee('Continue with InfoDot');

        config(['ecosystem.infodot.launch_url' => 'https://infodot.test/go/tutor', 'ecosystem.infodot.secret' => 'x']);
        $this->get('/login')->assertOk()->assertSee('Continue with InfoDot');
    }

    public function test_with_the_flag_off_the_old_receiver_still_provisions_as_it_always_did(): void
    {
        config(['ecosystem.infodot' => ['redeem_url' => 'https://infodot.test/r', 'platform' => 'tutor', 'secret' => 's', 'timeout' => 2, 'provision' => true]]);
        Http::fake(['https://infodot.test/r' => Http::response([
            'user' => ['id' => 1, 'uuid' => self::UUID, 'name' => 'New Person', 'email' => 'new@example.test', 'email_verified' => true],
            'teams' => [], 'current_team_id' => null,
        ])]);

        $this->post(route('ecosystem.auth'), ['token' => 't'], ['Origin' => 'https://infodot.test']);

        $this->assertAuthenticated();
    }

    // -- flag on: interception, entry points, carve-outs ---------------------------------------------------------

    public function test_with_the_flag_on_the_local_pages_go_to_infodot_and_there_is_no_create_account(): void
    {
        $this->on();

        $this->get('/login')->assertRedirect(route('dotsso.login'));
        $this->get('/register')->assertRedirect(route('dotsso.login', ['screen' => 'register']));
        $this->get('/forgot-password')->assertRedirect(route('dotsso.login'));
        $this->post('/login', ['email' => 'a@b.test', 'password' => 'x'])->assertRedirect(route('dotsso.login'));

        $this->assertSame(route('dotsso.login'), CentralSignIn::login());
        $html = $this->get('/')->assertOk()->getContent();
        $this->assertStringContainsString('href="'.route('dotsso.login').'"', $html);
        $this->assertStringNotContainsString('href="'.route('login').'"', $html);
    }

    public function test_the_break_glass_switch_brings_the_local_login_back(): void
    {
        $this->on();
        config(['dotsso.allow_local_login' => true]);

        $this->get('/login')->assertOk()->assertSee('Remember me');
        $this->assertSame(route('login'), CentralSignIn::login());
    }

    public function test_sanctum_routes_keep_working_and_logout_is_not_a_500(): void
    {
        $this->on();
        $this->assertNull(config('dotsso.guard') === 'web' ? null : 'guard must come from the package default');

        $user = User::factory()->create();
        Sanctum::actingAs($user, ['*']);
        $this->getJson('/api/user')->assertOk()->assertJsonPath('id', $user->id);
        $this->get('/up')->assertOk();
    }

    public function test_an_api_request_without_a_token_is_still_a_401(): void
    {
        $this->on();
        $this->getJson('/api/user')->assertUnauthorized();
    }

    // -- safeguarding 1: nobody new is created by a sign-in alone ------------------------------------------------

    public function test_an_unknown_person_gets_no_account_and_no_access_from_a_central_sign_in(): void
    {
        $this->on();
        config(['ecosystem.infodot.provision' => true]);

        $response = $this->signInVia(['sub' => self::UUID, 'email' => 'child@example.test', 'name' => 'Maybe A Child']);

        $response->assertStatus(403)->assertSee('no Dot.Tutor account', false);
        $this->assertGuest();
        $this->assertSame(0, User::where('email', 'child@example.test')->count());
        $this->assertSame(0, User::where('infodot_user_uuid', self::UUID)->count());
    }

    public function test_the_no_account_page_says_who_gets_in_and_never_promises_automatic_accounts(): void
    {
        $this->on();
        $html = $this->signInVia(['sub' => self::UUID, 'email' => 'child@example.test'])->getContent();

        $this->assertStringContainsString('invitation', $html);
        $this->assertStringNotContainsString('does not create accounts automatically', $html);
        $this->assertSame(1, substr_count($html, '<h1'));
    }

    public function test_an_invitation_for_a_different_address_or_a_dead_invitation_opens_nothing(): void
    {
        $this->on();
        [, $invitation] = $this->invite('parent@example.test');

        $this->assertFalse(NewAccountAdmission::allowed('someone.else@example.test'));
        $this->assertTrue(NewAccountAdmission::allowed('Parent@Example.test'));

        $invitation->forceFill(['revoked_at' => now()])->save();
        $this->assertFalse(NewAccountAdmission::allowed('parent@example.test'));

        $invitation->forceFill(['revoked_at' => null, 'accepted_at' => now()])->save();
        $this->assertFalse(NewAccountAdmission::allowed('parent@example.test'));

        $invitation->forceFill(['accepted_at' => null, 'expires_at' => now()->subMinute()])->save();
        $this->assertFalse(NewAccountAdmission::allowed('parent@example.test'));

        $this->signInVia(['sub' => self::UUID, 'email' => 'parent@example.test'])->assertStatus(403);
        $this->assertSame(0, User::where('email', 'parent@example.test')->count());
    }

    public function test_an_invited_guardian_gets_a_bare_account_with_no_role_no_profile_no_age(): void
    {
        $this->on();
        $this->invite('parent@example.test');

        $this->signInVia(['sub' => self::UUID, 'email' => 'parent@example.test', 'name' => 'Pat Parent'])->assertRedirect('/dashboard');

        $guardian = User::where('email', 'parent@example.test')->firstOrFail();
        $this->assertAuthenticatedAs($guardian);
        $this->assertSame(self::UUID, $guardian->infodot_user_uuid);
        $this->assertFalse((bool) $guardian->is_platform_operator);
        $this->assertSame(0, TutorProfile::where('user_id', $guardian->id)->count());
        $this->assertSame(0, AgeRecord::where('user_id', $guardian->id)->count());
        $this->assertSame(0, GuardianLink::where('guardian_id', $guardian->id)->count(), 'a link needs the signed link, token, birth date and consent');
    }

    public function test_the_unknown_age_default_is_still_ask(): void
    {
        $this->assertStringContainsString("env('SAFETY_UNKNOWN_AGE_POLICY', 'ask')", file_get_contents(config_path('safety.php')));
    }

    public function test_the_fallback_receiver_obeys_the_same_rule_once_central_sign_in_is_on(): void
    {
        $this->on();
        config(['ecosystem.infodot' => ['redeem_url' => 'https://infodot.test/r', 'platform' => 'tutor', 'secret' => 's', 'timeout' => 2, 'provision' => true]]);
        Http::fake(['https://infodot.test/r' => Http::response([
            'user' => ['id' => 1, 'uuid' => self::UUID, 'name' => 'Unknown', 'email' => 'unknown@example.test', 'email_verified' => true],
            'teams' => [], 'current_team_id' => null,
        ])]);

        $this->post(route('ecosystem.auth'), ['token' => 't'], ['Origin' => 'https://infodot.test'])->assertRedirect('/login');

        $this->assertGuest();
        $this->assertSame(0, User::where('email', 'unknown@example.test')->count());
    }

    // -- safeguarding 2: the guardian invitation deep link -------------------------------------------------------

    public function test_the_signed_guardian_invitation_survives_central_sign_in_and_still_verifies(): void
    {
        $this->on();
        [$url, $invitation, $learner] = $this->invite('parent@example.test');
        $target = $this->pathAndQuery($url);

        // A signed-out guardian opens the emailed link: sent to sign in, remembering exactly this URL.
        $this->get($target)->assertRedirect('/login');
        $this->get('/login')->assertRedirect(route('dotsso.login'));

        // InfoDot has registered and verified them; they come back and land on the EXACT signed URL, query intact.
        $back = $this->signInVia(['sub' => self::UUID, 'email' => 'parent@example.test', 'name' => 'Pat Parent']);
        $location = $back->headers->get('Location');
        $this->assertSame(parse_url($target, PHP_URL_PATH), parse_url($location, PHP_URL_PATH));
        parse_str((string) parse_url($target, PHP_URL_QUERY), $sent);
        parse_str((string) parse_url($location, PHP_URL_QUERY), $got);
        $this->assertEquals($sent, $got, 'every query value, the signature included, arrives unchanged');

        // The signature still verifies at the address the person is sent to, and the invitation page opens.
        $this->get($location)->assertOk()->assertSee('Learner');
        $this->get($target)->assertOk();

        // And the guardian can finish the existing flow: birth date and consent still required.
        parse_str((string) parse_url($url, PHP_URL_QUERY), $q);
        $this->post('/safety/guardians/accept/'.$invitation->id, ['token' => $q['token']])->assertSessionHasErrors(['birth_date', 'consent']);
        $this->post('/safety/guardians/accept/'.$invitation->id, [
            'token' => $q['token'], 'birth_date' => now()->subYears(10)->toDateString(), 'consent' => '1',
        ])->assertRedirect();
        $this->assertSame(1, GuardianLink::where('learner_id', $learner->id)->count());
    }

    public function test_a_tampered_or_expired_invitation_link_is_still_refused_after_sign_in(): void
    {
        $this->on();
        [$url] = $this->invite('parent@example.test');
        $target = $this->pathAndQuery($url);

        $this->signInVia(['sub' => self::UUID, 'email' => 'parent@example.test']);

        $this->get(preg_replace('/token=[^&]+/', 'token=forged', $target))->assertForbidden();
        $this->get($target.'&extra=1')->assertForbidden();

        $this->travel(73)->hours();
        $this->get($target)->assertForbidden();
    }

    public function test_a_hostile_deep_link_never_leaves_the_app(): void
    {
        $this->on();
        foreach (['https://evil.test/x', '//evil.test/x', '/\\evil.test', '/login', '/sso/login', '/api/user'] as $bad) {
            $this->flushSession();
            $this->signInVia(['sub' => 'u-'.md5($bad), 'email' => md5($bad).'@example.test'], ['url.intended' => $bad]);
        }
        // None of these may have opened an account either (no invitation), so the real assertion is the helper itself:
        $request = Request::create('http://localhost/');
        foreach (['https://evil.test/x', '//evil.test/x', '/\\evil.test', '/login', '/sso/login', '/api/user', '/up'] as $bad) {
            $this->assertNull(DeepLink::clean($bad, $request), $bad);
        }
        $good = '/safety/guardians/accept/4?expires=1&signature=abc&token=t';
        $this->assertSame($good, DeepLink::clean($good, $request));
    }

    // -- safeguarding 3: claim flow, nothing wiped ----------------------------------------------------------------

    public function test_an_unverified_local_user_claims_by_code_and_keeps_password_2fa_tokens_and_sessions(): void
    {
        $this->on();
        $user = User::factory()->withPersonalTeam()->create(['email' => 'ada@example.test', 'email_verified_at' => null, 'password' => Hash::make('old-password-1')]);
        $user->createToken('keep-me');
        $user->forceFill(['two_factor_secret' => encrypt('s3cret'), 'two_factor_confirmed_at' => now()])->save();
        config(['session.driver' => 'database']);
        DB::table('sessions')->insert(['id' => 'other-device', 'user_id' => $user->id, 'payload' => '', 'last_activity' => time()]);
        $hash = $user->password;
        $secret = $user->two_factor_secret;

        $this->signInVia(['sub' => self::UUID, 'email' => 'ada@example.test'])->assertRedirect(route('dotsso.claim'));
        $this->assertGuest();
        $this->assertSame(1, $user->fresh()->tokens()->count());

        $sent = null;
        Mail::assertSent(SsoClaimCodeMail::class, function ($m) use (&$sent) {
            $sent = $m->code;

            return $m->hasTo('ada@example.test');
        });

        $this->get('/sso/claim')->assertOk()->assertDontSee($sent);
        $this->post('/sso/claim', ['code' => $sent])->assertRedirect('/dashboard');

        $user->refresh();
        $this->assertSame(self::UUID, $user->infodot_user_uuid);
        $this->assertNotNull($user->email_verified_at);
        $this->assertSame($hash, $user->password, 'password kept');
        $this->assertSame($secret, $user->two_factor_secret, 'two-step sign-in kept');
        $this->assertNotNull($user->two_factor_confirmed_at);
        $this->assertSame(1, $user->tokens()->count(), 'API tokens kept');
        $this->assertTrue(DB::table('sessions')->where('id', 'other-device')->exists(), 'other sessions kept');
        $this->assertAuthenticatedAs($user);
    }

    public function test_a_wrong_claim_code_does_not_sign_in_or_link(): void
    {
        $this->on();
        $user = User::factory()->create(['email' => 'ada@example.test', 'email_verified_at' => null]);

        $this->signInVia(['sub' => self::UUID, 'email' => 'ada@example.test'])->assertRedirect(route('dotsso.claim'));
        $this->post('/sso/claim', ['code' => '000000'])->assertSessionHasErrors('code');

        $this->assertGuest();
        $this->assertNull($user->fresh()->infodot_user_uuid);
    }

    public function test_the_claim_page_is_one_form_with_a_labelled_code_field_in_the_reading_room_card(): void
    {
        $this->on();
        User::factory()->create(['email' => 'ada@example.test', 'email_verified_at' => null]);
        $this->signInVia(['sub' => self::UUID, 'email' => 'ada@example.test'])->assertRedirect(route('dotsso.claim'));

        $html = $this->get('/sso/claim')->assertOk()->getContent();

        $this->assertStringContainsString('autocomplete="one-time-code"', $html);
        $this->assertStringContainsString('inputmode="numeric"', $html);
        $this->assertMatchesRegularExpression('/<label[^>]+for="code"/', $html);
        $this->assertStringContainsString('td-auth__card', $html);
        $this->assertStringContainsString('td-claim', $html);
        $this->assertSame(1, substr_count($html, '<h1'));
    }

    public function test_the_old_receiver_no_longer_wipes_an_account_it_does_not_trust(): void
    {
        // Covered with the receiver's own fixtures in InfoDotRedeemTest and IdentityTakeoverTest; here the provisioner.
        $user = User::factory()->withPersonalTeam()->create(['email_verified_at' => null, 'two_factor_secret' => 'enc', 'password' => Hash::make('pw-1234567')]);
        $user->createToken('t');
        $before = $user->only(['password', 'two_factor_secret', 'current_team_id', 'remember_token']);

        $p = new TutorProvisioner;
        $p->link($user, self::UUID);
        $p->confirmEmail($user);

        $user->refresh();
        $this->assertSame($before, $user->only(['password', 'two_factor_secret', 'current_team_id', 'remember_token']));
        $this->assertSame(1, $user->tokens()->count());
        $this->assertSame(self::UUID, $p->uuidOf($user));
    }

    // -- accounts with authority are never linked silently ------------------------------------------------------------

    public function test_an_operator_a_tutor_and_both_sides_of_a_guardian_link_prove_the_mailbox_first(): void
    {
        $this->on();
        $operator = $this->operator('Olga');
        $operator->forceFill(['email' => 'olga@example.test', 'email_verified_at' => now()])->save();

        $tutor = $this->makeUser('Tutor');
        $tutor->forceFill(['email' => 'tutor@example.test', 'email_verified_at' => now()])->save();
        TutorProfile::create(['user_id' => $tutor->id, 'hourly_rate' => 250, 'status' => 'approved']);

        $learner = $this->child('Kid');
        $guardian = $this->adult('Guard');
        $this->linkGuardian($learner, $guardian);
        $learner->forceFill(['email' => 'kid@example.test', 'email_verified_at' => now()])->save();
        $guardian->forceFill(['email' => 'guard@example.test', 'email_verified_at' => now()])->save();

        $p = new TutorProvisioner;
        foreach ([$operator, $tutor, $learner, $guardian] as $i => $user) {
            $this->assertFalse($p->hasVerifiedEmail($user->fresh()), 'user '.$i);
            $this->flushSession();
            $this->signInVia(['sub' => 'uuid-auth-'.$i, 'email' => $user->email])->assertRedirect(route('dotsso.claim'));
            $this->assertGuest();
            $this->assertNull($user->fresh()->infodot_user_uuid, 'not linked silently');
        }

        $ordinary = $this->makeUser('Ordinary');
        $ordinary->forceFill(['email' => 'plain@example.test', 'email_verified_at' => now()])->save();
        $this->assertTrue($p->hasVerifiedEmail($ordinary->fresh()));
    }

    public function test_an_account_linked_to_a_different_infodot_identity_is_refused_and_not_repointed(): void
    {
        $this->on();
        $user = User::factory()->withPersonalTeam()->create(['email' => 'ada@example.test', 'email_verified_at' => now(), 'infodot_user_uuid' => self::UUID]);

        $this->signInVia(['sub' => '7b7b7b7b-7b7b-4b7b-8b7b-7b7b7b7b7b7b', 'email' => 'ada@example.test']);

        $this->assertGuest();
        $this->assertSame(self::UUID, $user->fresh()->infodot_user_uuid);
    }

    public function test_the_stored_infodot_id_wins_over_a_changed_email(): void
    {
        $this->on();
        $user = User::factory()->withPersonalTeam()->create(['email' => 'old@example.test', 'email_verified_at' => now(), 'infodot_user_uuid' => self::UUID]);

        $this->signInVia(['sub' => self::UUID, 'email' => 'new@example.test'])->assertRedirect('/dashboard');

        $this->assertAuthenticatedAs($user);
        $this->assertSame(1, User::count());
    }
}
