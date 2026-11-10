<?php

namespace Tests\Feature\Safety;

use App\Domain\Safety\Contracts\AgeGatedSpace;
use App\Domain\Safety\Contracts\SafeguardingGate;
use App\Domain\Safety\Enums\AgeBand;
use App\Domain\Safety\Models\AccountRestriction;
use App\Domain\Safety\Models\GuardianApproval;
use App\Domain\Safety\Services\DatabaseGuardianApprovals;
use App\Models\TutorSession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;
use Tests\TestCase;

class SafeguardingGateTest extends TestCase
{
    use BuildsSafetyState;
    use RefreshDatabase;

    private function gate(): SafeguardingGate
    {
        return app(SafeguardingGate::class);
    }

    private function space(array $bands, bool $paid = false, array $members = []): AgeGatedSpace
    {
        return new class($bands, $paid, $members) implements AgeGatedSpace
        {
            public function __construct(private array $bands, private bool $paid, private array $members) {}

            public function safetyAgeBands(): array
            {
                return $this->bands;
            }

            public function safetyIsPaid(): bool
            {
                return $this->paid;
            }

            public function safetyMemberIds(): array
            {
                return $this->members;
            }
        };
    }

    public function test_unknown_abilities_are_refused_loudly(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->gate()->allows($this->adult(), 'teleport');
    }

    public function test_a_signed_out_actor_is_denied(): void
    {
        $this->assertSame(['not_signed_in'], $this->gate()->allows(null, 'book')->codes());
    }

    public function test_a_suspended_account_is_denied_every_ability(): void
    {
        $adult = $this->adult();
        AccountRestriction::create(['user_id' => $adult->id, 'kind' => 'suspension', 'reason' => 'x', 'imposed_by_id' => $this->operator()->id, 'starts_at' => now()]);

        foreach (['book', 'pay', 'message', 'publish', 'upload'] as $ability) {
            $this->assertTrue($this->gate()->allows($adult, $ability, $this->adult('Other'))->hasReason('account_suspended'), $ability);
        }
    }

    public function test_a_limited_minor_cannot_book_pay_publish_or_upload(): void
    {
        $teen = $this->teen();
        $tutor = $this->adult('Tutor');
        $this->screen($tutor);

        foreach (['book', 'pay', 'publish', 'upload'] as $ability) {
            $this->assertTrue($this->gate()->allows($teen, $ability, $tutor)->hasReason('guardian_consent_required'), $ability);
        }
    }

    public function test_a_minor_cannot_book_an_unscreened_tutor(): void
    {
        $teen = $this->teen();
        $this->linkGuardian($teen);
        $profile = $this->makeTutor([], null, $this->adult('Tutor'));

        $this->assertSame(['tutor_screening_missing'], $this->gate()->allows($teen, 'book', $profile)->codes());
    }

    public function test_a_minor_booking_a_screened_tutor_needs_guardian_approval(): void
    {
        $child = $this->child();
        $this->linkGuardian($child);
        $tutorUser = $this->adult('Tutor');
        $this->screen($tutorUser);
        $profile = $this->makeTutor([], null, $tutorUser);

        $decision = $this->gate()->allows($child, 'book', $profile);

        $this->assertTrue($decision->allowed);
        $this->assertTrue($decision->requires('guardian_approval:booking'));
    }

    public function test_adults_book_and_pay_without_conditions(): void
    {
        $adult = $this->adult();
        $profile = $this->makeTutor([], null, $this->adult('Tutor'));

        $this->assertTrue($this->gate()->allows($adult, 'book', $profile)->allowed);
        $this->assertSame([], $this->gate()->allows($adult, 'book', $profile)->requirements);
        $this->assertSame([], $this->gate()->allows($adult, 'pay', $profile)->requirements);
    }

    public function test_a_minor_payment_needs_approval_until_approved(): void
    {
        $child = $this->child();
        $guardian = $this->adult('Guardian');
        $this->linkGuardian($child, $guardian);
        $purchasable = $this->makeTutor([], null, $this->adult('Seller'));

        $this->assertTrue($this->gate()->allows($child, 'pay', $purchasable)->requires('guardian_approval:purchase'));

        $approvals = app(DatabaseGuardianApprovals::class);
        $approval = $approvals->request($child, 'purchase', $purchasable, ['summary' => 'Revision pack', 'amount_minor' => 15000, 'currency' => 'ZAR']);
        $approvals->decide($approval, $guardian, true);

        $this->assertSame([], $this->gate()->allows($child, 'pay', $purchasable)->requirements);
        $this->assertSame(GuardianApproval::APPROVED, $approval->fresh()->status);
    }

    public function test_room_age_bands_are_enforced(): void
    {
        $child = $this->child();
        $this->linkGuardian($child);
        $teenRoom = $this->space([AgeBand::Teen]);
        $childRoom = $this->space([AgeBand::Child]);

        $this->assertTrue($this->gate()->allows($child, 'join_room', $teenRoom)->hasReason('age_band_not_allowed'));
        $this->assertTrue($this->gate()->allows($child, 'join_room', $childRoom)->allowed);

        $adult = $this->adult('Stranger');
        $this->assertTrue($this->gate()->allows($adult, 'join_room', $childRoom)->hasReason('adult_screening_missing'));

        $this->screen($adult);
        $this->assertTrue($this->gate()->allows($adult, 'join_room', $childRoom)->allowed);
        $this->assertTrue($this->gate()->allows($this->operator(), 'join_room', $childRoom)->allowed);
    }

    public function test_a_paid_room_needs_guardian_approval_for_a_minor(): void
    {
        $teen = $this->teen();
        $this->linkGuardian($teen);

        $decision = $this->gate()->allows($teen, 'join_room', $this->space([AgeBand::Teen], paid: true));

        $this->assertTrue($decision->requires('guardian_approval:paid_room'));
    }

    public function test_a_live_one_to_one_lesson_with_a_child_needs_guardian_approval_and_recording_needs_consent(): void
    {
        $child = $this->child();
        $guardian = $this->adult('Guardian');
        $this->linkGuardian($child, $guardian);
        $tutorUser = $this->adult('Tutor');
        $this->screen($tutorUser);
        $profile = $this->makeTutor([], null, $tutorUser);
        $session = $this->makeSession($profile, $child, $this->makeSubject('Maths'), ['status' => 'pending']);

        $this->assertSame(['guardian_approval_missing'], $this->gate()->allows($tutorUser, 'meet_live', $session)->codes());

        $approvals = app(DatabaseGuardianApprovals::class);
        $approvals->decide($approvals->request($child, 'live_lesson', $session), $guardian, true);

        $this->assertTrue($this->gate()->allows($tutorUser, 'meet_live', $session)->allowed);
        $this->assertSame(['recording_consent_missing'], $this->gate()->allows($tutorUser, 'meet_live', $session, ['record' => true])->codes());
    }

    public function test_recording_is_allowed_once_the_guardian_consents(): void
    {
        $child = $this->child();
        $guardian = $this->adult('Guardian');
        $this->linkGuardian($child, $guardian, recording: true);
        $tutorUser = $this->adult('Tutor');
        $this->screen($tutorUser);
        $session = $this->makeSession($this->makeTutor([], null, $tutorUser), $child, $this->makeSubject('Maths'));
        $approvals = app(DatabaseGuardianApprovals::class);
        $approvals->decide($approvals->request($child, 'live_lesson', $session), $guardian, true);

        $this->assertTrue($this->gate()->allows($tutorUser, 'meet_live', $session, ['record' => true])->allowed);
    }

    public function test_an_unscreened_adult_cannot_meet_a_minor_live(): void
    {
        $teen = $this->teen();
        $this->linkGuardian($teen);
        $tutorUser = $this->adult('Tutor');
        $profile = $this->makeTutor([], null, $tutorUser);
        $subject = $this->makeSubject('Maths');
        // Written around the booking backstop (which would refuse it) to test the live-lesson rule on its own.
        $session = TutorSession::withoutEvents(fn () => $this->makeSession($profile, $teen, $subject));

        $this->assertSame(['tutor_screening_missing'], $this->gate()->allows($tutorUser, 'meet_live', $session)->codes());
    }

    public function test_minors_cannot_sell_and_children_do_not_publish_publicly(): void
    {
        $teen = $this->teen();
        $this->linkGuardian($teen);
        $child = $this->child();
        $this->linkGuardian($child);

        $this->assertSame(['minor_cannot_sell'], $this->gate()->allows($teen, 'publish', null, ['commercial' => true])->codes());
        $this->assertTrue($this->gate()->allows($teen, 'publish', null, ['audience' => 'public'])->allowed);
        $this->assertSame(['child_public_publish'], $this->gate()->allows($child, 'publish', null, ['audience' => 'public'])->codes());
        $this->assertTrue($this->gate()->allows($child, 'publish', null, ['audience' => 'space'])->requires('moderation:pre'));
    }

    public function test_contact_details_are_hidden_when_a_minor_is_involved(): void
    {
        $teen = $this->teen();
        $guardian = $this->adult('Guardian');
        $this->linkGuardian($teen, $guardian);
        $tutor = $this->adult('Tutor');

        $this->assertSame(['contact_hidden_minor'], $this->gate()->allows($tutor, 'view_contact', $teen)->codes());
        $this->assertSame(['contact_hidden_minor'], $this->gate()->allows($teen, 'view_contact', $tutor)->codes());
        $this->assertTrue($this->gate()->allows($guardian, 'view_contact', $teen)->allowed);
        $this->assertTrue($this->gate()->allows($teen, 'view_contact', $guardian)->allowed);
        $this->assertTrue($this->gate()->allows($tutor, 'view_contact', $this->adult('Peer'))->allowed);
    }

    public function test_the_gate_is_exposed_as_laravel_abilities(): void
    {
        $teen = $this->teen();
        $profile = $this->makeTutor([], null, $this->adult('Tutor'));

        $this->assertTrue(Gate::forUser($teen)->denies('safety.book', $profile));
        $this->assertSame('guardian_consent_required', Gate::forUser($teen)->inspect('safety.book', $profile)->code());
        $this->assertTrue(Gate::forUser($this->adult())->allows('safety.book', $profile));
        $this->assertFalse(Gate::forUser($this->adult())->allows('safety.operate'));
        $this->assertTrue(Gate::forUser($this->operator())->allows('safety.operate'));
    }

    public function test_view_contact_needs_a_person(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->gate()->allows($this->adult(), 'view_contact', null);
    }

    public function test_messages_with_a_minor_need_a_context(): void
    {
        $child = $this->child();
        $this->linkGuardian($child);
        $adult = $this->adult('Stranger');
        $this->screen($adult);

        $direct = $this->gate()->allows($adult, 'message', $child, ['context_type' => 'direct']);
        $this->assertSame(['minor_needs_context'], $direct->codes());

        $peer = $this->child('Peer');
        $this->linkGuardian($peer);
        $this->assertSame(['minor_needs_context'], $this->gate()->allows($peer, 'message', $child, ['context_type' => 'direct'])->codes());

        $this->assertTrue($this->gate()->allows($adult, 'message', $this->adult('Other'), ['context_type' => 'direct'])->allowed);
        $this->assertTrue($this->gate()->allows($child, 'message', null, ['context_type' => 'support'])->allowed);
    }

    public function test_user_model_is_untouched(): void
    {
        $this->assertNotContains('is_platform_operator', (new User)->getFillable());
    }
}
