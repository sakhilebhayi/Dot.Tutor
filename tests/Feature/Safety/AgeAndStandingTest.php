<?php

namespace Tests\Feature\Safety;

use App\Domain\Safety\Enums\AgeBand;
use App\Domain\Safety\Models\AgeRecord;
use App\Domain\Safety\Services\AccountStanding;
use App\Domain\Safety\Services\AgeResolver;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AgeAndStandingTest extends TestCase
{
    use BuildsSafetyState;
    use RefreshDatabase;

    public function test_age_bands_follow_the_configured_thresholds(): void
    {
        $on = CarbonImmutable::parse('2026-11-10');

        $this->assertSame(AgeBand::Child, AgeBand::fromBirthDate(CarbonImmutable::parse('2013-11-11'), $on));
        $this->assertSame(AgeBand::Teen, AgeBand::fromBirthDate(CarbonImmutable::parse('2013-11-10'), $on));
        $this->assertSame(AgeBand::Teen, AgeBand::fromBirthDate(CarbonImmutable::parse('2008-11-11'), $on));
        $this->assertSame(AgeBand::Adult, AgeBand::fromBirthDate(CarbonImmutable::parse('2008-11-10'), $on));
    }

    public function test_the_birth_date_seam_is_read_when_safety_holds_no_record(): void
    {
        $learner = $this->makeUser('Learner');
        $this->bindBirthDates([$learner->id => now()->subYears(8)->toDateString()]);

        $this->assertSame(AgeBand::Child, app(AgeResolver::class)->band($learner));
    }

    public function test_an_operator_verified_date_outranks_the_self_declared_one(): void
    {
        $learner = $this->makeUser('Learner');
        $operator = $this->operator();
        $this->bindBirthDates([$learner->id => now()->subYears(25)->toDateString()]);
        AgeRecord::create(['user_id' => $learner->id, 'birth_date' => now()->subYears(12)->toDateString(), 'source' => 'operator_verified', 'recorded_by_id' => $operator->id]);

        $this->assertSame(AgeBand::Child, app(AgeResolver::class)->band($learner));
    }

    public function test_unknown_age_follows_the_configured_policy(): void
    {
        $user = $this->makeUser('Unknown');

        $this->assertSame(AgeBand::Adult, app(AgeResolver::class)->band($user));

        config(['safety.unknown_age_policy' => 'restrict']);
        $this->assertSame(AgeBand::Child, app(AgeResolver::class)->band($user));
    }

    public function test_a_minor_is_limited_until_a_guardian_links_and_consents(): void
    {
        $child = $this->child();
        $standing = app(AccountStanding::class);

        $this->assertTrue($standing->isLimited($child));
        $this->actingAs($child)->getJson(route('safety.account'))
            ->assertOk()->assertJsonPath('account.status', 'limited')->assertJsonPath('account.age_band', 'child');

        $this->linkGuardian($child);

        $this->assertFalse($standing->isLimited($child));
        $this->actingAs($child)->getJson(route('safety.account'))->assertJsonPath('account.status', 'active')
            ->assertJsonPath('account.guardian_consent', true);
    }

    public function test_an_adult_is_never_limited(): void
    {
        $adult = $this->adult();

        $this->actingAs($adult)->getJson(route('safety.account'))
            ->assertOk()->assertJsonPath('account.status', 'active')->assertJsonPath('account.is_minor', false);
    }
}
