<?php

namespace Tests\Feature\Security;

use App\Actions\Jetstream\AddTeamMember;
use App\Domain\Safety\Services\Guardians;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Contracts\UpdatesUserProfileInformation;
use Tests\Feature\Safety\BuildsSafetyState;
use Tests\TestCase;

/**
 * R-47 security S-01 / S-02: an email address is proof of identity only while it is the address InfoDot vouched for.
 */
class IdentityTakeoverTest extends TestCase
{
    use BuildsSafetyState;
    use RefreshDatabase;

    private const URL = 'https://infodot.test/api/ecosystem/redeem';

    protected function setUp(): void
    {
        parent::setUp();

        config(['ecosystem.infodot' => [
            'redeem_url' => self::URL, 'platform' => 'tutor', 'secret' => 'service-secret-for-tests', 'timeout' => 2, 'provision' => true,
        ]]);
    }

    private function changeEmail(User $user, string $email): void
    {
        app(UpdatesUserProfileInformation::class)->update($user, ['name' => $user->name, 'email' => $email]);
    }

    public function test_changing_the_email_address_drops_its_verification(): void
    {
        $attacker = User::factory()->create(['email_verified_at' => now()]);

        $this->changeEmail($attacker, 'victim@example.test');
        $this->assertNull($attacker->fresh()->email_verified_at);

        $kept = User::factory()->create(['email' => 'same@example.test', 'email_verified_at' => now()]);
        $this->changeEmail($kept, 'Same@Example.test');
        $this->assertNotNull($kept->fresh()->email_verified_at, 'Changing only the case keeps the proof');
    }

    public function test_the_real_owner_signing_in_through_infodot_shuts_the_squatter_out(): void
    {
        $attacker = User::factory()->create(['email_verified_at' => now()]);
        $this->changeEmail($attacker, 'victim@example.test');
        $attacker->createToken('squat');
        $attacker->forceFill(['two_factor_secret' => encrypt('x'), 'two_factor_confirmed_at' => now()])->save();
        $oldPassword = $attacker->fresh()->password;

        config(['session.driver' => 'database']);
        DB::table('sessions')->insert(['id' => 'attacker-session', 'user_id' => $attacker->id, 'payload' => '', 'last_activity' => time()]);

        Http::fake([self::URL => Http::response([
            'user' => ['id' => 9, 'name' => 'Victim', 'email' => 'victim@example.test', 'email_verified' => true],
            'teams' => [], 'current_team_id' => null,
        ])]);

        $host = parse_url(self::URL, PHP_URL_HOST);
        $this->post(route('ecosystem.auth'), ['token' => 'one-time'], ['Origin' => 'https://'.$host]);

        $adopted = $attacker->fresh();
        $this->assertNotNull($adopted->email_verified_at);
        $this->assertNotSame($oldPassword, $adopted->password);
        $this->assertNull($adopted->two_factor_secret);
        $this->assertSame(0, $adopted->tokens()->count());
        $this->assertFalse(DB::table('sessions')->where('id', 'attacker-session')->exists(), 'The squatter\'s sessions end');
    }

    public function test_a_team_invitation_is_not_accepted_into_an_unproven_address(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();
        $team = $owner->currentTeam ?? Team::query()->where('user_id', $owner->id)->firstOrFail();
        $attacker = User::factory()->create(['email_verified_at' => now()]);
        $this->changeEmail($attacker, 'invited@example.test');

        $this->expectException(ValidationException::class);
        app(AddTeamMember::class)->add($owner, $team, 'invited@example.test', 'editor');
    }

    public function test_a_guardian_invitation_needs_a_proven_address(): void
    {
        Notification::fake();
        $child = $this->child();
        $attacker = $this->adult('Sock');
        $this->changeEmail($attacker, 'mum@example.test');

        $result = app(Guardians::class)->invite($child, 'mum@example.test', 'parent');
        parse_str((string) parse_url($result['url'], PHP_URL_QUERY), $query);

        $this->actingAs($attacker)->postJson(route('safety.guardians.accept.store', $result['invitation']->id), [
            'token' => $query['token'], 'birth_date' => now()->subYears(9)->subMonths(2)->toDateString(), 'consent' => '1',
        ])->assertStatus(422);

        $this->assertDatabaseCount('safety_guardian_links', 0);
    }
}
