<?php

namespace Tests\Feature\Security;

use App\Domain\Money\Models\Order;
use App\Domain\Safety\Models\AccountRestriction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Jetstream\Http\Livewire\DeleteUserForm;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * R-47 security S-20, S-21 (docs/R47_PRIVACY.md): a person can take their data and close their account, and closing
 * never breaks the money or safeguarding records other people rely on.
 */
class PrivacyTest extends TestCase
{
    use RefreshDatabase;

    private function recent(User $user): self
    {
        return $this->actingAs($user)->withSession(['tutor.signed_in_at' => time()]);
    }

    private function order(User $buyer): void
    {
        Order::query()->forceCreate([
            'uuid' => (string) Str::uuid(), 'buyer_id' => $buyer->id, 'status' => 'paid', 'currency' => 'ZAR',
            'subtotal_minor' => 25000, 'total_minor' => 25000,
        ]);
    }

    public function test_the_page_needs_a_sign_in_and_shows_both_actions(): void
    {
        $this->get(route('security.privacy'))->assertRedirect('/login');

        $this->actingAs(User::factory()->create())->get(route('security.privacy'))->assertOk()
            ->assertSee('Download my data')->assertSee('Close my account');
    }

    public function test_download_my_data_needs_a_recent_sign_in_and_holds_only_my_records_without_secrets(): void
    {
        $me = User::factory()->create(['name' => 'Lindiwe Export']);
        $other = User::factory()->create();
        $this->order($me);
        $this->order($other);
        $me->createToken('x');

        $this->actingAs($me)->getJson(route('security.export'))->assertStatus(423);

        $response = $this->recent($me)->get(route('security.export'))->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private');
        $this->assertStringContainsString('attachment;', (string) $response->headers->get('Content-Disposition'));

        $data = json_decode((string) $response->getContent(), true);
        $this->assertSame('Lindiwe Export', $data['account']['name']);
        $this->assertCount(1, $data['records']['money_orders']);
        $this->assertSame($me->id, $data['records']['money_orders'][0]['buyer_id']);
        $this->assertStringNotContainsString('"password"', $response->getContent());
        $this->assertStringNotContainsString('remember_token', $response->getContent());
        $this->assertArrayNotHasKey('personal_access_tokens', $data['records']);
        $this->assertDatabaseHas('safety_audit_log', ['action' => 'account.data_exported']);
    }

    public function test_an_account_without_history_is_deleted(): void
    {
        $me = User::factory()->withPersonalTeam()->create();

        $this->recent($me)->post(route('security.close'), ['confirm' => 'close'])->assertSessionHasErrors('confirm');
        $this->recent($me)->post(route('security.close'), ['confirm' => 'CLOSE'])->assertRedirect('/');

        $this->assertNull($me->fresh());
    }

    public function test_an_account_with_purchases_is_closed_not_deleted_and_cannot_be_used_again(): void
    {
        $me = User::factory()->withPersonalTeam()->create(['name' => 'Sipho Buyer', 'email' => 'sipho@example.test']);
        $this->order($me);
        DB::table('learner_profiles')->insert(['user_id' => $me->id, 'created_at' => now(), 'updated_at' => now()]);

        $this->recent($me)->post(route('security.close'), ['confirm' => 'CLOSE'])->assertRedirect('/');

        $closed = $me->fresh();
        $this->assertNotNull($closed, 'kept for the accounting records');
        $this->assertSame('Closed account', $closed->name);
        $this->assertStringEndsWith('@closed.invalid', $closed->email);
        $this->assertNull($closed->email_verified_at);
        $this->assertSame(0, DB::table('learner_profiles')->where('user_id', $me->id)->count());
        $this->assertSame(1, Order::query()->where('buyer_id', $me->id)->count());

        $this->app['auth']->forgetGuards();
        $this->flushSession();
        $this->post('/login', ['email' => 'sipho@example.test', 'password' => 'password']);
        $this->assertGuest();
    }

    public function test_jetstreams_delete_form_closes_an_account_with_history_instead_of_failing(): void
    {
        $me = User::factory()->withPersonalTeam()->create();
        $this->order($me);
        $this->actingAs($me);

        Livewire::test(DeleteUserForm::class)->set('password', 'password')->call('deleteUser');

        $this->assertSame('Closed account', $me->fresh()->name);
    }

    public function test_a_person_under_a_safeguarding_restriction_keeps_their_name_on_the_record(): void
    {
        $me = User::factory()->withPersonalTeam()->create(['name' => 'Restricted Person', 'email' => 'restricted@example.test']);
        $operator = User::factory()->create();
        $operator->forceFill(['is_platform_operator' => true])->save();
        AccountRestriction::query()->forceCreate([
            'user_id' => $me->id, 'kind' => AccountRestriction::SUSPENSION, 'reason' => 'Test', 'imposed_by_id' => $operator->id, 'starts_at' => now(),
        ]);

        $this->recent($me)->post(route('security.close'), ['confirm' => 'CLOSE']);

        $this->assertSame('Restricted Person', $me->fresh()->name);
        $this->assertStringEndsWith('@closed.invalid', $me->fresh()->email);
        $audit = DB::table('safety_audit_log')->where('action', 'account.closed')->first();
        $this->assertStringContainsString('restricted@example.test', (string) $audit->context);
    }

    public function test_the_purge_deletes_what_has_outlived_its_purpose_and_keeps_the_rest(): void
    {
        $learner = User::factory()->create();
        $old = ['learner_id' => $learner->id, 'invited_by_id' => $learner->id, 'email' => 'old@example.test', 'relationship' => 'parent',
            'token_hash' => str_repeat('a', 64), 'expires_at' => now()->subDays(40), 'created_at' => now()->subDays(43), 'updated_at' => now()];
        DB::table('safety_guardian_invitations')->insert($old);
        DB::table('safety_guardian_invitations')->insert(['token_hash' => str_repeat('b', 64), 'email' => 'new@example.test', 'expires_at' => now()->addDay()] + $old);
        DB::table('password_reset_tokens')->insert(['email' => 'x@example.test', 'token' => 'x', 'created_at' => now()->subDays(2)]);

        $this->artisan('tutor:privacy-purge --dry-run')->assertSuccessful();
        $this->assertSame(2, DB::table('safety_guardian_invitations')->count(), 'a dry run deletes nothing');

        $this->artisan('tutor:privacy-purge')->assertSuccessful();
        $this->assertSame(['new@example.test'], DB::table('safety_guardian_invitations')->pluck('email')->all());
        $this->assertSame(0, DB::table('password_reset_tokens')->count());
    }
}
