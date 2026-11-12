<?php

namespace Tests\Feature\Safety;

use App\Domain\Safety\Models\Consent;
use App\Domain\Safety\Models\GuardianInvitation;
use App\Domain\Safety\Models\GuardianLink;
use App\Domain\Safety\Notifications\GuardianInvited;
use App\Domain\Safety\Services\Guardians;
use App\Domain\Safety\Support\ConsentText;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class GuardianLinkingTest extends TestCase
{
    use BuildsSafetyState;
    use RefreshDatabase;

    /** @return array{0: string, 1: GuardianInvitation, 2: string} url, invitation, token */
    private function invite(User $learner, string $email): array
    {
        Notification::fake();

        $response = $this->actingAs($learner)->postJson(route('safety.guardians.invite'), ['email' => $email, 'relationship' => 'parent'])
            ->assertCreated();

        $url = $response->json('accept_url');
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        return [$url, GuardianInvitation::findOrFail($response->json('invitation.id')), $query['token']];
    }

    public function test_a_learner_invites_a_guardian_by_email(): void
    {
        $child = $this->child();
        [, $invitation] = $this->invite($child, 'Parent@Example.com');

        $this->assertSame('parent@example.com', $invitation->email);
        $this->assertNotNull($invitation->token_hash);
        Notification::assertSentTo(new AnonymousNotifiable, GuardianInvited::class,
            fn ($n, $channels, $notifiable) => $notifiable->routes['mail'] === 'parent@example.com');
    }

    public function test_the_guardian_signs_in_reviews_and_consents(): void
    {
        $child = $this->child();
        $guardian = $this->adult('Parent');
        [$url, $invitation, $token] = $this->invite($child, $guardian->email);

        $this->actingAs($guardian)->get($url)->assertOk()->assertSee('agree', false);

        $this->actingAs($guardian)->postJson(route('safety.guardians.accept.store', $invitation->id), [
            'token' => $token,
            // The month an operator already verified (a different one is refused: R-47 security S-06).
            'birth_date' => now()->subYears(9)->subMonths(2)->toDateString(),
            'consent' => '1',
            'recording_consent' => true,
        ])->assertCreated()->assertJsonPath('link.status', 'active');

        $consent = Consent::query()->where('learner_id', $child->id)->where('kind', Consent::PROCESSING)->sole();
        $this->assertSame(config('safety.guardians.consent_version'), $consent->version);
        $this->assertSame(ConsentText::hash('processing'), $consent->text_hash);
        $this->assertNotNull($consent->granted_at);
        $this->assertTrue(Consent::query()->where('kind', Consent::LESSON_RECORDING)->exists());
        $this->assertNotNull($invitation->fresh()->accepted_at);
        $this->assertDatabaseHas('safety_age_records', ['user_id' => $child->id, 'source' => 'guardian_declared']);
        $this->assertDatabaseHas('safety_audit_log', ['action' => 'safety.guardian.linked', 'actor_id' => $guardian->id]);
    }

    public function test_the_accept_page_needs_a_valid_signature(): void
    {
        $child = $this->child();
        $guardian = $this->adult('Parent');
        [$url] = $this->invite($child, $guardian->email);

        $this->actingAs($guardian)->get(preg_replace('/signature=[^&]+/', 'signature=forged', $url))->assertForbidden();
    }

    public function test_the_accept_page_sends_signed_out_visitors_to_sign_in(): void
    {
        Notification::fake();
        $result = app(Guardians::class)->invite($this->child(), 'someone@example.com', 'parent');

        $this->get($result['url'])->assertRedirect('/login');
    }

    public function test_consent_must_be_given(): void
    {
        $child = $this->child();
        $guardian = $this->adult('Parent');
        [, $invitation, $token] = $this->invite($child, $guardian->email);

        $this->actingAs($guardian)->postJson(route('safety.guardians.accept.store', $invitation->id), [
            'token' => $token, 'birth_date' => now()->subYears(9)->toDateString(),
        ])->assertUnprocessable()->assertJsonValidationErrors('consent');

        $this->assertSame(0, GuardianLink::count());
    }

    public function test_a_different_signed_in_account_cannot_use_the_link(): void
    {
        $child = $this->child();
        [, $invitation, $token] = $this->invite($child, 'parent@example.com');
        $stranger = $this->adult('Stranger');

        $this->actingAs($stranger)->postJson(route('safety.guardians.accept.store', $invitation->id), [
            'token' => $token, 'birth_date' => now()->subYears(9)->toDateString(), 'consent' => '1',
        ])->assertUnprocessable()->assertJsonValidationErrors('invitation');

        $this->assertSame(0, GuardianLink::count());
    }

    public function test_a_wrong_token_or_expired_invitation_is_gone(): void
    {
        $child = $this->child();
        $guardian = $this->adult('Parent');
        [, $invitation, $token] = $this->invite($child, $guardian->email);

        $this->actingAs($guardian)->postJson(route('safety.guardians.accept.store', $invitation->id), [
            'token' => 'wrong', 'birth_date' => now()->subYears(9)->toDateString(), 'consent' => '1',
        ])->assertStatus(410);

        $this->travel(73)->hours();

        $this->actingAs($guardian)->postJson(route('safety.guardians.accept.store', $invitation->id), [
            'token' => $token, 'birth_date' => now()->subYears(9)->toDateString(), 'consent' => '1',
        ])->assertStatus(410);
    }

    public function test_a_guardian_must_be_an_adult_and_the_learner_a_minor(): void
    {
        $child = $this->child();
        $teenGuardian = $this->teen('Sibling');
        [, $invitation, $token] = $this->invite($child, $teenGuardian->email);

        $this->actingAs($teenGuardian)->postJson(route('safety.guardians.accept.store', $invitation->id), [
            'token' => $token, 'birth_date' => now()->subYears(9)->toDateString(), 'consent' => '1',
        ])->assertUnprocessable();

        $guardian = $this->adult('Parent');
        [, $second, $token2] = $this->invite($child, $guardian->email);

        $this->actingAs($guardian)->postJson(route('safety.guardians.accept.store', $second->id), [
            'token' => $token2, 'birth_date' => now()->subYears(20)->toDateString(), 'consent' => '1',
        ])->assertUnprocessable()->assertJsonValidationErrors('birth_date');
    }

    public function test_a_learner_cannot_invite_their_own_address_or_flood_invitations(): void
    {
        $child = $this->child();
        Notification::fake();

        $this->actingAs($child)->postJson(route('safety.guardians.invite'), ['email' => $child->email, 'relationship' => 'parent'])
            ->assertUnprocessable();

        foreach (range(1, 5) as $i) {
            GuardianInvitation::create(['learner_id' => $child->id, 'invited_by_id' => $child->id, 'email' => "p$i@example.com",
                'relationship' => 'parent', 'token_hash' => hash('sha256', (string) $i), 'expires_at' => now()->addDay()]);
        }

        $this->actingAs($child)->postJson(route('safety.guardians.invite'), ['email' => 'late@example.com', 'relationship' => 'parent'])
            ->assertUnprocessable();
    }

    public function test_the_guardian_revokes_and_the_account_is_limited_again(): void
    {
        $child = $this->child();
        $guardian = $this->adult('Parent');
        $link = $this->linkGuardian($child, $guardian);

        $this->actingAs($guardian)->postJson(route('safety.guardian.links.revoke', $link), ['reason' => 'Moving platforms'])
            ->assertOk()->assertJsonPath('link.status', 'revoked');

        $this->assertNotNull(Consent::query()->where('guardian_link_id', $link->id)->first()->withdrawn_at);
        $this->actingAs($child)->getJson(route('safety.account'))->assertJsonPath('account.status', 'limited');
    }

    public function test_the_learner_cannot_revoke_their_guardian(): void
    {
        $child = $this->child();
        $link = $this->linkGuardian($child);

        $this->actingAs($child)->postJson(route('safety.guardian.links.revoke', $link))->assertForbidden();
        $this->assertSame(GuardianLink::ACTIVE, $link->fresh()->status);
    }

    public function test_an_operator_can_revoke_a_link_with_a_reason(): void
    {
        $child = $this->child();
        $link = $this->linkGuardian($child);

        $this->actingAs($this->adult('Nobody'))->postJson(route('safety.operator.guardian-links.revoke', $link), ['reason' => 'x'])->assertForbidden();
        $this->actingAs($this->operator())->postJson(route('safety.operator.guardian-links.revoke', $link), ['reason' => 'Court order'])
            ->assertOk()->assertJsonPath('link.status', 'revoked');
    }

    public function test_recording_consent_can_be_given_and_withdrawn(): void
    {
        $child = $this->child();
        $guardian = $this->adult('Parent');
        $link = $this->linkGuardian($child, $guardian);

        $this->actingAs($guardian)->postJson(route('safety.guardian.links.recording', $link), ['consent' => true])
            ->assertOk()->assertJsonPath('recording_consent', true);
        $this->actingAs($guardian)->postJson(route('safety.guardian.links.recording', $link), ['consent' => false])
            ->assertOk()->assertJsonPath('recording_consent', false);
    }
}
